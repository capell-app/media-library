<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\MediaHealthAuthorization;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static int run(Authenticatable|null $actor, array<int, array{table: string, column: string}>|null $ownerForeignKeys = null, int $limit = 100, array<int, int|string>|null $mediaIds = null)
 */
final class DeleteOrphanMediaRecordsAction
{
    use AsFake;
    use AsObject;

    /**
     * Deletes orphaned Curator media rows AND their underlying storage blobs.
     *
     * Files shared by another (non-deleted) Curator row on the same disk+path
     * are left on disk to avoid breaking still-referenced or derived assets.
     *
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @param  array<int, int|string>|null  $mediaIds
     * @return int Number of database rows deleted.
     */
    public function handle(?Authenticatable $actor, ?array $ownerForeignKeys = null, int $limit = 100, ?array $mediaIds = null): int
    {
        MediaHealthAuthorization::authorizeOrphanMediaDeletion($actor);

        $limit = max(1, min($limit, 1000));
        $mediaIds = $this->normalizeMediaIds($mediaIds);

        if ($mediaIds !== null && $mediaIds === []) {
            return 0;
        }

        $knownOwnerForeignKeys = ResolveOwnerForeignKeysAction::run($ownerForeignKeys);

        if ($knownOwnerForeignKeys === []) {
            return 0;
        }

        $orphanQuery = BuildOrphanMediaQueryAction::run($ownerForeignKeys, false);

        if ($mediaIds !== null) {
            $orphanQuery->whereIn((new CuratorMedia)->getQualifiedKeyName(), $mediaIds);
        }

        $orphans = $orphanQuery->limit($limit)->get();

        if ($orphans->isEmpty()) {
            return 0;
        }

        $orphanIds = $orphans->map($this->mediaKey(...))->all();

        // Revalidate owner usage under the same transaction that locks and
        // deletes candidates. A row can gain an owner after the report query
        // runs, so candidate selection alone is never sufficient authority to
        // remove a physical blob.
        return DB::transaction(function () use ($actor, $knownOwnerForeignKeys, $orphanIds): int {
            $usageCountExpression = resolve(MediaUsageQueryExpressions::class)
                ->usageCountExpression($knownOwnerForeignKeys);

            $lockedOrphans = CuratorMedia::query()
                ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $orphanIds)
                ->whereRaw('(' . $usageCountExpression . ') = 0')
                ->lockForUpdate()
                ->get();

            $lockedOrphanIds = $lockedOrphans
                ->map($this->mediaKey(...))
                ->all();

            if ($lockedOrphanIds === []) {
                return 0;
            }

            foreach ($lockedOrphans as $orphan) {
                $this->deleteUnsharedFiles($orphan, $lockedOrphanIds);
            }

            $deleted = CuratorMedia::query()
                ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $lockedOrphanIds)
                ->delete();

            Log::notice('Orphan media records deleted.', [
                'actor_id' => $actor?->getAuthIdentifier(),
                'media_ids' => $lockedOrphanIds,
                'count' => count($lockedOrphanIds),
            ]);

            return is_int($deleted) ? $deleted : 0;
        });
    }

    private function mediaKey(CuratorMedia $media): int
    {
        $key = $media->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }

    /**
     * Removes the original blob plus any derived/conversion blobs, but only
     * when no other Curator row (outside the set being deleted) references the
     * same disk+path.
     *
     * @param  array<int, int>  $orphanIds
     */
    private function deleteUnsharedFiles(CuratorMedia $media, array $orphanIds): void
    {
        $disk = $media->getAttribute('disk');
        $path = $media->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return;
        }

        // The shared-reference query joins the locked candidate set, so it sees
        // a consistent snapshot under the surrounding transaction's row locks.
        $sharedByOtherRow = CuratorMedia::query()
            ->where('disk', $disk)
            ->where('path', $path)
            ->whereNotIn((new CuratorMedia)->getKeyName(), $orphanIds)
            ->exists();

        if ($sharedByOtherRow) {
            return;
        }

        try {
            $storageDisk = Storage::disk($disk);

            foreach ($this->blobPathsToDelete($media, $path) as $blobPath) {
                if ($blobPath !== '' && $storageDisk->exists($blobPath)) {
                    $storageDisk->delete($blobPath);
                }
            }
        } catch (Throwable) {
            // A missing or misconfigured disk must not abort the row cleanup;
            // the orphan record is still removed by the caller.
        }
    }

    /**
     * Original path plus any derived/conversion blob paths recorded on the
     * media row (responsive_images / generated_conversions). De-duplicated.
     *
     * @return list<string>
     */
    private function blobPathsToDelete(CuratorMedia $media, string $originalPath): array
    {
        $paths = [$originalPath];

        foreach ($this->derivedPathCandidates($media) as $derivedPath) {
            $paths[] = $derivedPath;
        }

        return array_values(array_unique(array_filter(
            $paths,
            static fn (string $path): bool => $path !== '',
        )));
    }

    /**
     * @return list<string>
     */
    private function derivedPathCandidates(CuratorMedia $media): array
    {
        $candidates = [];
        $attributes = $media->getAttributes();

        foreach (['responsive_images', 'generated_conversions'] as $metadataKey) {
            $value = $attributes[$metadataKey] ?? null;

            if (is_string($value) && $value !== '') {
                $value = json_decode($value, true);
            }

            if (is_array($value)) {
                $this->collectStringPaths($value, $candidates);
            }
        }

        return $candidates;
    }

    /**
     * Recursively collect file-path-looking strings from nested conversion
     * metadata, ignoring full URLs and srcset width descriptors.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $candidates
     */
    private function collectStringPaths(array $value, array &$candidates): void
    {
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $this->collectStringPaths($entry, $candidates);

                continue;
            }

            if (! is_string($entry) || $entry === '') {
                continue;
            }

            foreach (preg_split('/[\s,]+/', $entry) ?: [] as $token) {
                $token = trim($token);

                // Skip width descriptors (e.g. "1024w") and absolute URLs; only
                // keep relative storage paths that the disk can resolve.
                if ($token === '' || preg_match('/^\d+w$/', $token) === 1) {
                    continue;
                }

                if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $token) === 1 || str_starts_with($token, '//')) {
                    continue;
                }

                $candidates[] = ltrim($token, '/');
            }
        }
    }

    /**
     * @param  array<int, int|string>|null  $mediaIds
     * @return array<int, int>|null
     */
    private function normalizeMediaIds(?array $mediaIds): ?array
    {
        if ($mediaIds === null) {
            return null;
        }

        return collect($mediaIds)
            ->filter(static fn (mixed $mediaId): bool => (is_int($mediaId) || is_string($mediaId)) && is_numeric($mediaId) && (int) $mediaId > 0)
            ->map(static fn (mixed $mediaId): int => (int) $mediaId)
            ->unique()
            ->values()
            ->all();
    }
}
