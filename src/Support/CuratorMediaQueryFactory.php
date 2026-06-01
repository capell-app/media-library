<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CuratorMediaQueryFactory
{
    /**
     * @param  array<int, string>  $extraSelects
     * @return Builder<CuratorMedia>
     */
    public function emptyQuery(array $extraSelects = []): Builder
    {
        $query = CuratorMedia::query();
        $emptyCuratorTable = DB::query()
            ->selectRaw($this->emptyCuratorColumns())
            ->whereRaw('1 = 0');

        $query->getQuery()->fromSub($emptyCuratorTable, 'curator');

        $query->select('curator.*');

        foreach ($extraSelects as $extraSelect) {
            $query->selectRaw($extraSelect);
        }

        return $query;
    }

    private function emptyCuratorColumns(): string
    {
        return implode(', ', [
            'null as id',
            'null as disk',
            'null as directory',
            'null as visibility',
            'null as name',
            'null as path',
            'null as width',
            'null as height',
            'null as size',
            'null as type',
            'null as ext',
            'null as alt',
            'null as title',
            'null as description',
            'null as caption',
            'null as pretty_name',
            'null as exif',
            'null as curations',
            'null as created_at',
            'null as updated_at',
        ]);
    }
}
