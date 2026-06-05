<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class DeleteOrphanMediaRecordsAction
{
    use AsAction;

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
    public function handle(?array $ownerForeignKeys = null, int $limit = 100, ?array $mediaIds = null): int
    {
        $limit = max(1, min($limit, 1000));
        $mediaIds = $this->normalizeMediaIds($mediaIds);

        if ($mediaIds !== null && $mediaIds === []) {
            return 0;
        }

        /** @var Collection<int, CuratorMedia> $orphans */
        $orphanQuery = BuildOrphanMediaQueryAction::run($ownerForeignKeys);

        if ($mediaIds !== null) {
            $orphanQuery->whereIn((new CuratorMedia)->getQualifiedKeyName(), $mediaIds);
        }

        $orphans = $orphanQuery->limit($limit)->get();

        if ($orphans->isEmpty()) {
            return 0;
        }

        $orphanIds = $orphans->map(static fn (CuratorMedia $media): int => (int) $media->getKey())->all();

        foreach ($orphans as $orphan) {
            $this->deleteUnsharedFile($orphan, $orphanIds);
        }

        return CuratorMedia::query()
            ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $orphanIds)
            ->delete();
    }

    /**
     * Removes the blob only when no other Curator row (outside the set being
     * deleted) references the same disk+path.
     *
     * @param  array<int, int>  $orphanIds
     */
    private function deleteUnsharedFile(CuratorMedia $media, array $orphanIds): void
    {
        $disk = $media->getAttribute('disk');
        $path = $media->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return;
        }

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

            if ($storageDisk->exists($path)) {
                $storageDisk->delete($path);
            }
        } catch (Throwable) {
            // A missing or misconfigured disk must not abort the row cleanup;
            // the orphan record is still removed below.
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
