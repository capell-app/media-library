<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support\UsageResolvers;

use Awcodes\Curator\Models\Media as BaseCuratorMedia;
use Capell\Core\Models\Media as CoreMedia;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\TableName;
use Capell\MediaLibrary\Contracts\MediaUsageReferenceResolver;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Content graph edges that target the media. Every edge kind and strength
 * counts: an informational edge still names the media, and keeping it is safe.
 */
final class ContentGraphUsageResolver implements MediaUsageReferenceResolver
{
    public function referencedMediaIds(Collection $media, ?array $ownerForeignKeys = null): array
    {
        $table = TableName::resolve('content_graph_edges');
        $schema = resolve(RuntimeSchemaState::class);

        if (! $schema->hasTable($table)) {
            return [];
        }

        $ids = $media->map(static fn (CuratorMedia $item): int => is_numeric($item->getKey()) ? (int) $item->getKey() : 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $referenced = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $found = DB::table($table)
                ->whereIn('target_type', $this->targetTypes())
                ->whereIn('target_id', $chunk)
                ->distinct()
                ->pluck('target_id');

            foreach ($found as $mediaId) {
                if (is_numeric($mediaId)) {
                    $referenced[(int) $mediaId] = (int) $mediaId;
                }
            }
        }

        return array_values($referenced);
    }

    /**
     * @return list<string>
     */
    private function targetTypes(): array
    {
        $types = [
            CuratorMedia::class,
            BaseCuratorMedia::class,
            CoreMedia::class,
            'curator_media',
            'media',
        ];

        foreach ([CuratorMedia::class, BaseCuratorMedia::class, CoreMedia::class] as $class) {
            $types[] = Relation::getMorphAlias($class);
        }

        return array_values(array_filter(array_unique($types), is_string(...)));
    }
}
