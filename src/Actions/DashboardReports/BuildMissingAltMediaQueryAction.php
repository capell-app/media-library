<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildMissingAltMediaQueryAction
{
    use AsAction;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return Builder<CuratorMedia>
     */
    public function handle(?array $ownerForeignKeys = null, bool $onlyImages = true): Builder
    {
        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
            return $this->emptyCuratorQuery();
        }

        $usageExpressions = resolve(MediaUsageQueryExpressions::class);
        $knownOwnerForeignKeys = ResolveOwnerForeignKeysAction::run($ownerForeignKeys);
        $usageCountExpression = $usageExpressions->usageCountExpression($knownOwnerForeignKeys);

        return CuratorMedia::query()
            ->select('curator.*')
            ->selectRaw($usageCountExpression . ' as usage_count')
            ->selectRaw("'missing_alt' as media_missing_alt_signal")
            ->where(function (Builder $curatorQuery): void {
                $curatorQuery
                    ->whereNull('alt')
                    ->orWhere('alt', '')
                    ->orWhereRaw("trim(alt) = ''");
            })
            ->when($onlyImages, fn (Builder $curatorQuery): Builder => $curatorQuery->where('type', 'like', 'image/%'))
            ->orderByDesc('usage_count')
            ->oldest('updated_at')
            ->orderBy('id');
    }

    /**
     * @return Builder<CuratorMedia>
     */
    private function emptyCuratorQuery(): Builder
    {
        return resolve(CuratorMediaQueryFactory::class)->emptyQuery(['0 as usage_count', "'missing_alt' as media_missing_alt_signal"]);
    }
}
