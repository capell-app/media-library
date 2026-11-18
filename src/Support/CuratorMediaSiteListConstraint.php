<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\Core\Support\Permissions\SiteAccess;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Keeps another site's media out of list and picker queries, in one query so pagination and totals stay correct.
 *
 * The rule matches CuratorMediaSiteAccess: an item is listed when it has no site-scoped usage or at least one of its
 * sites is the actor's. The usage is read from the content graph, the owner foreign-key tables and the structured
 * usage index, which attributes media referenced only from layout, block and settings JSON to the record's site.
 */
final readonly class CuratorMediaSiteListConstraint
{
    public function __construct(private CuratorMediaSiteAccess $siteAccess) {}

    /**
     * @param  Builder<*>  $query
     */
    public function apply(Builder $query, ?Authenticatable $actor): void
    {
        if (! $actor instanceof Authenticatable) {
            return;
        }

        $allowed = SiteAccess::forActor($actor)->allowedSiteIds();

        if ($allowed === null) {
            return;
        }

        $sources = $this->siteAccess->siteUsageSources();

        if ($sources === []) {
            return;
        }

        $mediaKey = $query->getModel()->getQualifiedKeyName();

        $query->where(function (Builder $visible) use ($sources, $allowed, $mediaKey): void {
            // Visible when no source records usage on a site the actor lacks, or when one records usage on their own site.
            $visible->where(function (Builder $unrestricted) use ($sources, $allowed, $mediaKey): void {
                foreach ($sources as $source) {
                    $unrestricted->whereNotExists($this->usage($source, $mediaKey, $allowed, false));
                }
            })->orWhere(function (Builder $own) use ($sources, $allowed, $mediaKey): void {
                foreach ($sources as $source) {
                    $own->orWhereExists($this->usage($source, $mediaKey, $allowed, true));
                }
            });
        });
    }

    /**
     * @param  array{table: string, column: string, types: list<string>|null}  $source
     * @param  list<int>  $allowed
     * @return Closure(QueryBuilder): void
     */
    private function usage(array $source, string $mediaKey, array $allowed, bool $own): Closure
    {
        return static function (QueryBuilder $usage) use ($source, $mediaKey, $allowed, $own): void {
            $usage->select(DB::raw('1'))
                ->from($source['table'])
                ->whereColumn($source['table'] . '.' . $source['column'], $mediaKey)
                ->whereNotNull($source['table'] . '.site_id');

            if ($source['types'] !== null) {
                $usage->whereIn($source['table'] . '.target_type', $source['types']);
            }

            $own
                ? $usage->whereIn($source['table'] . '.site_id', $allowed)
                : $usage->whereNotIn($source['table'] . '.site_id', $allowed);
        };
    }
}
