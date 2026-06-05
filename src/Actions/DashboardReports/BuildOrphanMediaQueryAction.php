<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Data\MediaOwnerForeignKeyData;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static Builder<CuratorMedia> run(array<int, array{table: string, column: string}>|null $ownerForeignKeys = null, bool $useCache = true)
 */
final class BuildOrphanMediaQueryAction
{
    use AsAction;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return Builder<CuratorMedia>
     */
    public function handle(?array $ownerForeignKeys = null, bool $useCache = true): Builder
    {
        $emptyQueryFactory = resolve(CuratorMediaQueryFactory::class);

        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
            return $emptyQueryFactory->emptyQuery(['0 as usage_count']);
        }

        $usageExpressions = resolve(MediaUsageQueryExpressions::class);
        $knownOwnerForeignKeys = ResolveOwnerForeignKeysAction::run($ownerForeignKeys);

        if ($knownOwnerForeignKeys === []) {
            return $emptyQueryFactory->emptyQuery(['0 as usage_count']);
        }

        $usageCountExpression = $usageExpressions->usageCountExpression($knownOwnerForeignKeys);

        $query = CuratorMedia::query()
            ->select('curator.*')
            ->selectRaw($usageCountExpression . ' as usage_count')
            ->whereRaw('(' . $usageCountExpression . ') = 0')
            ->latest('curator.updated_at')
            ->orderByDesc('curator.id');

        if (! $useCache || $this->cacheTtlSeconds() < 1) {
            return $query;
        }

        /** @var list<array{id: int, usage_count: int}> $rows */
        $rows = Cache::remember(
            $this->cacheKey($knownOwnerForeignKeys),
            $this->cacheTtlSeconds(),
            fn (): array => $query
                ->get()
                ->map(static fn (CuratorMedia $media): array => [
                    'id' => self::intValue($media->getKey()),
                    'usage_count' => self::intValue($media->getAttribute('usage_count')),
                ])
                ->values()
                ->all(),
        );

        return $emptyQueryFactory->cachedReportRowsQuery($rows, [
            'usage_count' => 0,
        ]);
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function cacheTtlSeconds(): int
    {
        $ttlSeconds = config('capell.media_library.report_cache_ttl_seconds', 60);

        return is_numeric($ttlSeconds) ? max(0, (int) $ttlSeconds) : 60;
    }

    /**
     * @param  array<int, MediaOwnerForeignKeyData>  $knownOwnerForeignKeys
     */
    private function cacheKey(array $knownOwnerForeignKeys): string
    {
        return 'capell-media-library:orphans:' . sha1(json_encode([
            'owner_foreign_keys' => $this->ownerForeignKeyPayload($knownOwnerForeignKeys),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, MediaOwnerForeignKeyData>  $knownOwnerForeignKeys
     * @return list<array{table: string, column: string}>
     */
    private function ownerForeignKeyPayload(array $knownOwnerForeignKeys): array
    {
        $payload = collect($knownOwnerForeignKeys)
            ->map(static fn (MediaOwnerForeignKeyData $ownerForeignKey): array => [
                'table' => $ownerForeignKey->table,
                'column' => $ownerForeignKey->column,
            ])
            ->sortBy(static fn (array $ownerForeignKey): string => $ownerForeignKey['table'] . ':' . $ownerForeignKey['column'])
            ->values()
            ->all();

        return array_values($payload);
    }
}
