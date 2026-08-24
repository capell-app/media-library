<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Data\MediaOwnerForeignKeyData;
use Capell\MediaLibrary\Enums\MediaHealthIssue;
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

        /** @var literal-string $usageCountExpression */
        $query = CuratorMedia::query()
            ->select('curator.*')
            ->selectRaw($usageCountExpression . ' as usage_count');

        // Each issue is projected independently: a record that is missing alt
        // text AND stale AND unused reports all three, instead of collapsing to
        // the first branch of a single case expression.
        $missingAltSelect = $this->missingAltFlagExpression() . ' as ' . MediaHealthIssue::MissingAlt->flagColumn();
        $staleSelect = $this->staleFlagExpression() . ' as ' . MediaHealthIssue::Stale->flagColumn();
        $unusedSelect = $this->unusedFlagExpression($usageCountExpression, $knownOwnerForeignKeys !== [])
            . ' as ' . MediaHealthIssue::Unused->flagColumn();

        (new SqlFragment($missingAltSelect))->applySelect($query->getQuery());
        (new SqlFragment($staleSelect, [$staleThreshold->toDateTimeString()]))->applySelect($query->getQuery());
        (new SqlFragment($unusedSelect))->applySelect($query->getQuery());

        $query
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

        /** @var list<array<string, int>> $rows */
        $rows = Cache::remember(
            $this->cacheKey($knownOwnerForeignKeys, $this->staleAfterDays()),
            $this->cacheTtlSeconds(),
            fn (): array => $query
                ->get()
                ->map(static function (CuratorMedia $media): array {
                    $row = [
                        'id' => self::intValue($media->getKey()),
                        'usage_count' => self::intValue($media->getAttribute('usage_count')),
                    ];

                    foreach (MediaHealthIssue::all() as $issue) {
                        $row[$issue->flagColumn()] = self::intValue($media->getAttribute($issue->flagColumn()));
                    }

                    return $row;
                })
                ->values()
                ->all(),
        );

        return resolve(CuratorMediaQueryFactory::class)->cachedReportRowsQuery($rows, self::cachedColumnDefaults());
    }

    /**
     * @return array<string, int>
     */
    private static function cachedColumnDefaults(): array
    {
        $defaults = ['usage_count' => 0];

        foreach (MediaHealthIssue::all() as $issue) {
            $defaults[$issue->flagColumn()] = 0;
        }

        return $defaults;
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return Builder<CuratorMedia>
     */
    private function emptyCuratorQuery(): Builder
    {
        $selects = ['0 as usage_count'];

        foreach (MediaHealthIssue::all() as $issue) {
            $selects[] = '0 as ' . $issue->flagColumn();
        }

        return resolve(CuratorMediaQueryFactory::class)->emptyQuery($selects);
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

    private function missingAltFlagExpression(): string
    {
        return "case when alt is null or alt = '' then 1 else 0 end";
    }

    private function staleFlagExpression(): string
    {
        return 'case when updated_at < ? then 1 else 0 end';
    }

    private function unusedFlagExpression(string $usageCountExpression, bool $hasOwnerForeignKeys): string
    {
        if (! $hasOwnerForeignKeys) {
            return '0';
        }

        return sprintf('case when (%s) = 0 then 1 else 0 end', $usageCountExpression);
    }
}
