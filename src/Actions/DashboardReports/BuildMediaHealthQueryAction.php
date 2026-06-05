<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildMediaHealthQueryAction
{
    use AsAction;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return Builder<CuratorMedia>
     */
    public function handle(?array $ownerForeignKeys = null): Builder
    {
        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
            return $this->emptyCuratorQuery();
        }

        $staleThreshold = now()->subDays($this->staleAfterDays());
        $usageExpressions = resolve(MediaUsageQueryExpressions::class);
        $knownOwnerForeignKeys = ResolveOwnerForeignKeysAction::run($ownerForeignKeys);
        $usageCountExpression = $usageExpressions->usageCountExpression($knownOwnerForeignKeys);
        $issueExpression = $this->issueExpression($usageCountExpression, $knownOwnerForeignKeys !== []);

        return CuratorMedia::query()
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
