<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildOrphanMediaQueryAction
{
    use AsAction;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return Builder<CuratorMedia>
     */
    public function handle(?array $ownerForeignKeys = null): Builder
    {
        $emptyQueryFactory = resolve(CuratorMediaQueryFactory::class);

        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
            return $emptyQueryFactory->emptyQuery(['0 as usage_count']);
        }

        $usageExpressions = resolve(MediaUsageQueryExpressions::class);
        $knownOwnerForeignKeys = $usageExpressions->knownOwnerForeignKeys(
            $ownerForeignKeys ?? config('capell.media_library.owner_foreign_keys', []),
        );

        if ($knownOwnerForeignKeys === []) {
            return $emptyQueryFactory->emptyQuery(['0 as usage_count']);
        }

        $usageCountExpression = $usageExpressions->usageCountExpression($knownOwnerForeignKeys);

        return CuratorMedia::query()
            ->select('curator.*')
            ->selectRaw($usageCountExpression . ' as usage_count')
            ->whereRaw('(' . $usageCountExpression . ') = 0')
            ->latest('curator.updated_at')
            ->orderByDesc('curator.id');
    }
}
