<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Awcodes\Curator\Models\Media as BaseCuratorMedia;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Support\TableName;
use Capell\MediaLibrary\Actions\Usage\SyncStructuredMediaUsageAction;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\UsageResolvers\OwnerForeignKeyUsageResolver;
use Capell\MediaLibrary\Support\UsageResolvers\StructuredColumnUsageResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Curator rows have no owning record, so a site is derived from where the media is used: content graph edges, owner
 * foreign-key tables and the structured columns (block, meta and layout JSON, rich text) that hold Curator ids.
 *
 * An administrator who is not global may act on media that is unused (still in the shared library) or used only by
 * content with no site. When any site-scoped usage exists they need access to at least one of those sites, so a
 * site-less reference elsewhere never opens media that another site uses. Usage is memoised per request, because the
 * policy runs for every row of a list.
 */
final class CuratorMediaSiteAccess
{
    /** @var array<int, array{siteIds: list<int>, unscoped: bool}> */
    private array $usage = [];

    /** @var list<array{table: string, column: string, hasSite: bool}>|null */
    private ?array $ownerColumns = null;

    /** @var list<array{table: string, column: string, types: list<string>|null}>|null */
    private ?array $siteUsageSources = null;

    public static function canUse(?Authenticatable $actor, CuratorMedia $media): bool
    {
        return resolve(self::class)->allows($actor, $media);
    }

    public function allows(?Authenticatable $actor, CuratorMedia $media): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        $access = SiteAccess::forActor($actor);

        if ($access->isGlobal()) {
            return true;
        }

        $mediaId = $media->getKey();

        if (! is_int($mediaId) && ! (is_string($mediaId) && ctype_digit($mediaId))) {
            return false;
        }

        $siteIds = $this->usage((int) $mediaId, $media)['siteIds'];

        if ($siteIds === []) {
            return true;
        }

        foreach ($siteIds as $siteId) {
            if ($access->canSiteId($siteId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tables whose rows record where a Curator item is used, each with the column holding the item id, the
     * content graph target types to match (null for owner tables) and a `site_id` column. Owner tables without a
     * site column carry no site, so they never grant or deny access and are left out. Resolved once per request.
     *
     * @return list<array{table: string, column: string, types: list<string>|null}>
     */
    public function siteUsageSources(): array
    {
        if ($this->siteUsageSources !== null) {
            return $this->siteUsageSources;
        }

        $sources = [];
        $graphTable = TableName::resolve('content_graph_edges');

        if (resolve(RuntimeSchemaState::class)->hasTable($graphTable)) {
            $sources[] = ['table' => $graphTable, 'column' => 'target_id', 'types' => $this->curatorTargetTypes()];
        }

        $structuredTable = SyncStructuredMediaUsageAction::table();

        if (resolve(RuntimeSchemaState::class)->hasTable($structuredTable)) {
            $sources[] = ['table' => $structuredTable, 'column' => 'media_id', 'types' => null];
        }

        foreach ($this->ownerColumns() as $owner) {
            if ($owner['hasSite']) {
                $sources[] = ['table' => $owner['table'], 'column' => $owner['column'], 'types' => null];
            }
        }

        return $this->siteUsageSources = $sources;
    }

    /**
     * @return array{siteIds: list<int>, unscoped: bool}
     */
    private function usage(int $mediaId, CuratorMedia $media): array
    {
        return $this->usage[$mediaId] ??= $this->resolveUsage($mediaId, $media);
    }

    /**
     * @return array{siteIds: list<int>, unscoped: bool}
     */
    private function resolveUsage(int $mediaId, CuratorMedia $media): array
    {
        $siteIds = [];
        $unscoped = false;

        $graphTable = TableName::resolve('content_graph_edges');

        if (resolve(RuntimeSchemaState::class)->hasTable($graphTable)) {
            $edges = DB::table($graphTable)
                ->whereIn('target_type', $this->curatorTargetTypes())
                ->where('target_id', $mediaId)
                ->distinct()
                ->pluck('site_id');

            foreach ($edges as $siteId) {
                is_numeric($siteId) ? $siteIds[(int) $siteId] = (int) $siteId : $unscoped = true;
            }
        }

        foreach ($this->ownerColumns() as $owner) {
            $query = DB::table($owner['table'])->where($owner['column'], $mediaId);

            if (! $owner['hasSite']) {
                $unscoped = $unscoped || $query->exists();

                continue;
            }

            foreach ($query->distinct()->pluck('site_id') as $siteId) {
                is_numeric($siteId) ? $siteIds[(int) $siteId] = (int) $siteId : $unscoped = true;
            }
        }

        $structured = resolve(StructuredColumnUsageResolver::class)->siteUsage($media);

        foreach ($structured['siteIds'] as $siteId) {
            $siteIds[$siteId] = $siteId;
        }

        return ['siteIds' => array_values($siteIds), 'unscoped' => $unscoped || $structured['unscoped']];
    }

    /**
     * @return list<array{table: string, column: string, hasSite: bool}>
     */
    private function ownerColumns(): array
    {
        if ($this->ownerColumns !== null) {
            return $this->ownerColumns;
        }

        $schema = resolve(RuntimeSchemaState::class);
        $columns = [];

        foreach (resolve(OwnerForeignKeyUsageResolver::class)->ownerForeignKeys(null) as $ownerForeignKey) {
            $columns[] = [
                'table' => $ownerForeignKey->table,
                'column' => $ownerForeignKey->column,
                'hasSite' => $schema->hasColumn($ownerForeignKey->table, 'site_id'),
            ];
        }

        return $this->ownerColumns = $columns;
    }

    /**
     * Content graph producers that target Core's Spatie media use the `media` type with Spatie ids, which are a
     * different id space, so only Curator-typed edges are read here.
     *
     * @return list<string>
     */
    private function curatorTargetTypes(): array
    {
        return array_values(array_unique(array_filter([
            CuratorMedia::class,
            BaseCuratorMedia::class,
            'curator_media',
            Relation::getMorphAlias(CuratorMedia::class),
            Relation::getMorphAlias(BaseCuratorMedia::class),
        ], is_string(...))));
    }
}
