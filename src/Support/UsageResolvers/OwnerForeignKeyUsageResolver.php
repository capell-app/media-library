<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support\UsageResolvers;

use Capell\MediaLibrary\Actions\DashboardReports\DiscoverOwnerForeignKeysAction;
use Capell\MediaLibrary\Contracts\MediaUsageReferenceResolver;
use Capell\MediaLibrary\Data\MediaOwnerForeignKeyData;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owner foreign key columns. For deletion the union of the configured, the
 * caller-passed and the schema-discovered columns is used, so a partial list
 * can never hide a column that still references the media.
 */
final class OwnerForeignKeyUsageResolver implements MediaUsageReferenceResolver
{
    public function referencedMediaIds(Collection $media, ?array $ownerForeignKeys = null): array
    {
        $ids = $media->map(static fn (CuratorMedia $item): int => is_numeric($item->getKey()) ? (int) $item->getKey() : 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $referenced = [];

        foreach ($this->ownerForeignKeys($ownerForeignKeys) as $ownerForeignKey) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $found = DB::table($ownerForeignKey->table)
                    ->whereIn($ownerForeignKey->column, $chunk)
                    ->distinct()
                    ->pluck($ownerForeignKey->column);

                foreach ($found as $mediaId) {
                    if (is_numeric($mediaId)) {
                        $referenced[(int) $mediaId] = (int) $mediaId;
                    }
                }
            }
        }

        return array_values($referenced);
    }

    /**
     * @param  array<int, array{table: string, column: string}>|null  $passed
     * @return array<int, MediaOwnerForeignKeyData>
     */
    private function ownerForeignKeys(?array $passed): array
    {
        $configured = config('capell.media_library.owner_foreign_keys', []);

        $candidates = [
            ...($passed ?? []),
            ...(is_array($configured) ? $configured : []),
            ...DiscoverOwnerForeignKeysAction::run(force: true),
        ];

        $unique = [];

        foreach (resolve(MediaUsageQueryExpressions::class)->knownOwnerForeignKeys($candidates) as $ownerForeignKey) {
            $unique[$ownerForeignKey->table . '.' . $ownerForeignKey->column] = $ownerForeignKey;
        }

        return array_values($unique);
    }
}
