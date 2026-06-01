<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildDuplicateMediaQueryAction
{
    use AsAction;

    /**
     * @return Builder<CuratorMedia>
     */
    public function handle(): Builder
    {
        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
            return resolve(CuratorMediaQueryFactory::class)->emptyQuery(['0 as duplicate_count']);
        }

        $duplicateRows = DB::query()
            ->from('curator')
            ->select('disk', 'path')
            ->selectRaw('count(*) as duplicate_count')
            ->whereNotNull('path')
            ->where('path', '<>', '')
            ->groupBy('disk', 'path')
            ->havingRaw('count(*) > 1');

        return CuratorMedia::query()
            ->select('curator.*')
            ->selectRaw('duplicate_rows.duplicate_count as duplicate_count')
            ->joinSub($duplicateRows, 'duplicate_rows', function (JoinClause $join): void {
                $join
                    ->on('curator.disk', '=', 'duplicate_rows.disk')
                    ->on('curator.path', '=', 'duplicate_rows.path');
            })
            ->orderBy('curator.disk')
            ->orderBy('curator.path')
            ->orderBy('curator.id');
    }
}
