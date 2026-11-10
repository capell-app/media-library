<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions;

use Capell\MediaLibrary\Data\PurgeQuarantinedMediaResult;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\MediaBlobPaths;
use Capell\MediaLibrary\Support\MediaUsageReferenceChecker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static PurgeQuarantinedMediaResult run(?int $retentionDays = null, int $batchSize = 200)
 */
final class PurgeQuarantinedMediaAction
{
    use AsFake;
    use AsObject;

    /**
     * Permanently removes media quarantined for longer than the retention
     * period. Usage is re-checked under lock with every resolver first, and a
     * row that is referenced again is restored instead. Blobs are removed only
     * after the row deletion has committed, and every failed removal is
     * reported rather than swallowed.
     */
    public function handle(?int $retentionDays = null, int $batchSize = 200): PurgeQuarantinedMediaResult
    {
        $cutoff = now()->subDays($retentionDays ?? $this->configuredRetentionDays());
        $batchSize = max(1, min($batchSize, 1000));

        $purgedIds = [];
        $restoredIds = [];
        $failures = [];
        $lastId = 0;

        while (true) {
            $ids = array_values(CuratorMedia::onlyTrashed()
                ->where('deleted_at', '<=', $cutoff)
                ->where((new CuratorMedia)->getQualifiedKeyName(), '>', $lastId)
                ->orderBy((new CuratorMedia)->getQualifiedKeyName())
                ->limit($batchSize)
                ->get()
                ->map($this->key(...))
                ->all());

            if ($ids === []) {
                break;
            }

            $lastId = max($ids);

            /** @var array{purged: list<int>, restored: list<int>, blobs: list<array{media_id: int, disk: string, path: string, paths: list<string>}>} $batch */
            $batch = DB::transaction(fn (): array => $this->purgeBatch($ids, $cutoff));

            // The rows are gone for good; only now is it safe to touch the blobs.
            foreach ($batch['blobs'] as $blob) {
                foreach ($this->deleteBlobs($blob) as $failure) {
                    $failures[] = $failure;
                }
            }

            array_push($purgedIds, ...$batch['purged']);
            array_push($restoredIds, ...$batch['restored']);
        }

        if ($failures !== []) {
            Log::warning('Quarantined media blobs could not be deleted.', [
                'count' => count($failures),
                'failures' => $failures,
            ]);
        }

        if ($purgedIds !== [] || $restoredIds !== []) {
            Log::notice('Quarantined media purged.', [
                'purged_ids' => $purgedIds,
                'restored_ids' => $restoredIds,
            ]);
        }

        return new PurgeQuarantinedMediaResult($purgedIds, $restoredIds, $failures);
    }

    /**
     * @param  list<int>  $ids
     * @return array{purged: list<int>, restored: list<int>, blobs: list<array{media_id: int, disk: string, path: string, paths: list<string>}>}
     */
    private function purgeBatch(array $ids, mixed $cutoff): array
    {
        /** @var Collection<int, CuratorMedia> $locked */
        $locked = CuratorMedia::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $ids)
            ->lockForUpdate()
            ->get();

        $referencedIds = resolve(MediaUsageReferenceChecker::class)->referencedMediaIds($locked);

        $restored = $locked->filter(fn (CuratorMedia $media): bool => in_array($this->key($media), $referencedIds, true));
        $purging = $locked->reject(fn (CuratorMedia $media): bool => in_array($this->key($media), $referencedIds, true));

        if ($restored->isNotEmpty()) {
            CuratorMedia::onlyTrashed()
                ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $restored->map($this->key(...))->all())
                ->restore();
        }

        $purgingIds = array_values($purging->map($this->key(...))->all());
        $blobs = [];

        foreach ($purging as $media) {
            $blob = $this->blobPlan($media, $purgingIds);

            if ($blob !== null) {
                $blobs[] = $blob;
            }
        }

        if ($purgingIds !== []) {
            CuratorMedia::onlyTrashed()
                ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $purgingIds)
                ->forceDelete();
        }

        return [
            'purged' => $purgingIds,
            'restored' => array_values($restored->map($this->key(...))->all()),
            'blobs' => $blobs,
        ];
    }

    /**
     * Blobs to remove for a row, unless another Curator row (quarantined or
     * not) outside the purge set still points at the same disk and path.
     *
     * @param  list<int>  $purgingIds
     * @return array{media_id: int, disk: string, path: string, paths: list<string>}|null
     */
    private function blobPlan(CuratorMedia $media, array $purgingIds): ?array
    {
        $disk = $media->getAttribute('disk');
        $path = $media->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return null;
        }

        $shared = CuratorMedia::withTrashed()
            ->where('disk', $disk)
            ->where('path', $path)
            ->whereNotIn((new CuratorMedia)->getKeyName(), $purgingIds)
            ->exists();

        if ($shared) {
            return null;
        }

        return [
            'media_id' => $this->key($media),
            'disk' => $disk,
            'path' => $path,
            'paths' => resolve(MediaBlobPaths::class)->forMedia($media, $path),
        ];
    }

    /**
     * @param  array{media_id: int, disk: string, path: string, paths: list<string>}  $blob
     * @return list<array{media_id: int, disk: string, path: string, error: string}>
     */
    private function deleteBlobs(array $blob): array
    {
        $failures = [];

        foreach ($blob['paths'] as $blobPath) {
            try {
                $storage = Storage::disk($blob['disk']);

                if ($storage->exists($blobPath) && $storage->delete($blobPath) !== true) {
                    $failures[] = $this->failure($blob, $blobPath, 'The disk refused to delete the file.');
                }
            } catch (Throwable $throwable) {
                $failures[] = $this->failure($blob, $blobPath, $throwable->getMessage());
            }
        }

        return $failures;
    }

    /**
     * @param  array{media_id: int, disk: string, path: string, paths: list<string>}  $blob
     * @return array{media_id: int, disk: string, path: string, error: string}
     */
    private function failure(array $blob, string $path, string $error): array
    {
        return ['media_id' => $blob['media_id'], 'disk' => $blob['disk'], 'path' => $path, 'error' => $error];
    }

    private function key(CuratorMedia $media): int
    {
        $key = $media->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }

    private function configuredRetentionDays(): int
    {
        $days = config('capell.media_library.quarantine_retention_days', 30);

        return is_numeric($days) ? max(0, (int) $days) : 30;
    }
}
