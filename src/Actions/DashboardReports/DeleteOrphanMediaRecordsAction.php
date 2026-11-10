<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\MediaHealthAuthorization;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Capell\MediaLibrary\Support\MediaUsageReferenceChecker;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(Authenticatable|null $actor, array<int, array{table: string, column: string}>|null $ownerForeignKeys = null, int $limit = 100, array<int, int|string>|null $mediaIds = null)
 */
final class DeleteOrphanMediaRecordsAction
{
    use AsFake;
    use AsObject;

    /**
     * Quarantines orphaned Curator media rows (soft delete). No blob is removed
     * here; PurgeQuarantinedMediaAction deletes rows and files once the
     * retention period has passed and the media is still unreferenced.
     *
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @param  array<int, int|string>|null  $mediaIds
     * @return int Number of rows moved to quarantine.
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

        // Revalidate usage under the same transaction that locks and
        // quarantines candidates. A row can gain a reference after the report
        // query runs, and the owner foreign keys alone cannot see media used
        // from JSON, rich text or the content graph, so every configured
        // resolver must agree the row is unreferenced.
        return DB::transaction(function () use ($actor, $knownOwnerForeignKeys, $orphanIds, $ownerForeignKeys): int {
            $usageCountExpression = resolve(MediaUsageQueryExpressions::class)
                ->usageCountExpression($knownOwnerForeignKeys);

            /** @var literal-string $usageCountExpression */
            $lockedOrphans = CuratorMedia::query()
                ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $orphanIds)
                ->whereRaw('(' . $usageCountExpression . ') = 0')
                ->lockForUpdate()
                ->get();

            $referencedIds = resolve(MediaUsageReferenceChecker::class)
                ->referencedMediaIds($lockedOrphans, $ownerForeignKeys);

            $quarantineIds = $lockedOrphans
                ->map($this->mediaKey(...))
                ->reject(static fn (int $id): bool => in_array($id, $referencedIds, true))
                ->values()
                ->all();

            if ($quarantineIds === []) {
                return 0;
            }

            // Quarantine only. Blobs stay on disk until PurgeQuarantinedMediaAction
            // removes the row after the retention period.
            $quarantined = CuratorMedia::query()
                ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $quarantineIds)
                ->delete();

            Log::notice('Orphan media records quarantined.', [
                'actor_id' => $actor?->getAuthIdentifier(),
                'media_ids' => $quarantineIds,
                'count' => count($quarantineIds),
            ]);

            return is_int($quarantined) ? $quarantined : 0;
        });
    }

    private function mediaKey(CuratorMedia $media): int
    {
        $key = $media->getKey();

        return is_numeric($key) ? (int) $key : 0;
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
