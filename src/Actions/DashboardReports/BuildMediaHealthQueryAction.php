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
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Builder<CuratorMedia> run(array<int, array{table: string, column: string}>|null $ownerForeignKeys = null, bool $useCache = true)
 */
final class BuildMediaHealthQueryAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return Builder<CuratorMedia>
     */
    public function handle(?array $ownerForeignKeys = null, bool $useCache = true): Builder
    {
        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
            return $this->emptyCuratorQuery();
        }

        $staleThreshold = now()->subDays($this->staleAfterDays());
        $usageExpressions = resolve(MediaUsageQueryExpressions::class);
        $knownOwnerForeignKeys = ResolveOwnerForeignKeysAction::run($ownerForeignKeys);
        $usageCountExpression = $usageExpressions->usageCountExpression($knownOwnerForeignKeys);
        $issueExpression = $this->issueExpression($usageCountExpression, $knownOwnerForeignKeys !== []);

        $query = CuratorMedia::query()
            ->select('curator.*')
            ->selectRaw($usageCountExpression . ' as usage_count')
            ->selectRaw($issueExpression . ' as media_health_issue', [$staleThreshold->toDateTimeString()])
            ->where(function (Builder $nestedCuratorQuery) use ($knownOwnerForeignKeys, $staleThreshold, $usageCountExpression): void {
                $nestedCuratorQuery
                    ->whereNull('alt')
                    ->orWhere('alt', '')
                    ->orWhere('updated_at', '<', $staleThreshold);

                if ($knownOwnerForeignKeys !== []) {
                    $nestedCuratorQuery->orWhereRaw('(' . $usageCountExpression . ') = 0');
                }
            });

        if (! $useCache || $this->cacheTtlSeconds() < 1) {
            return $query;
        }

        /** @var list<array{id: int, usage_count: int, media_health_issue: string}> $rows */
        $rows = Cache::remember(
            $this->cacheKey($knownOwnerForeignKeys, $this->staleAfterDays()),
            $this->cacheTtlSeconds(),
            fn (): array => $query
                ->get()
                ->map(static fn (CuratorMedia $media): array => [
                    'id' => self::intValue($media->getKey()),
                    'usage_count' => self::intValue($media->getAttribute('usage_count')),
                    'media_health_issue' => self::stringValue($media->getAttribute('media_health_issue'), 'healthy'),
                ])
                ->values()
                ->all(),
        );

        return resolve(CuratorMediaQueryFactory::class)->cachedReportRowsQuery($rows, [
            'usage_count' => 0,
            'media_health_issue' => 'healthy',
        ]);
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringValue(mixed $value, string $default): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    /**
     * @return Builder<CuratorMedia>
     */
    private function emptyCuratorQuery(): Builder
    {
        return resolve(CuratorMediaQueryFactory::class)->emptyQuery(['0 as usage_count', "'healthy' as media_health_issue"]);
    }

    private function staleAfterDays(): int
    {
        $staleAfterDays = config('capell.media_library.stale_after_days', 90);

        return is_numeric($staleAfterDays) ? max(1, (int) $staleAfterDays) : 90;
    }

    private function cacheTtlSeconds(): int
    {
        $ttlSeconds = config('capell.media_library.report_cache_ttl_seconds', 60);

        return is_numeric($ttlSeconds) ? max(0, (int) $ttlSeconds) : 60;
    }

    /**
     * @param  array<int, MediaOwnerForeignKeyData>  $knownOwnerForeignKeys
     */
    private function cacheKey(array $knownOwnerForeignKeys, int $staleAfterDays): string
    {
        return 'capell-media-library:health:' . hash('sha256', json_encode([
            'owner_foreign_keys' => $this->ownerForeignKeyPayload($knownOwnerForeignKeys),
            'stale_after_days' => $staleAfterDays,
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

    private function issueExpression(string $usageCountExpression, bool $hasOwnerForeignKeys): string
    {
        $unusedClause = $hasOwnerForeignKeys
            ? sprintf("when (%s) = 0 then 'unused'", $usageCountExpression)
            : '';

        return sprintf(
            "case when alt is null or alt = '' then 'missing_alt' when updated_at < ? then 'stale' %s else 'healthy' end",
            $unusedClause,
        );
    }
}
