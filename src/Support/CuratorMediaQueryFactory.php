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

    /**
     * @param  list<array<string, int|string>>  $rows
     * @param  array<string, int|string>  $extraColumnDefaults
     * @return Builder<CuratorMedia>
     */
    public function cachedReportRowsQuery(array $rows, array $extraColumnDefaults = []): Builder
    {
        if ($rows === []) {
            return $this->emptyQuery($this->defaultSelects($extraColumnDefaults));
        }

        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($row['id']) && is_numeric($row['id']) && (int) $row['id'] > 0,
        ));

        if ($rows === []) {
            return $this->emptyQuery($this->defaultSelects($extraColumnDefaults));
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $query = CuratorMedia::query()
            ->select('curator.*')
            ->whereIn((new CuratorMedia)->getQualifiedKeyName(), $ids);

        foreach ($extraColumnDefaults as $column => $defaultValue) {
            [$expression, $bindings] = $this->caseExpression($rows, $column, $defaultValue);

            $query->selectRaw($expression . ' as ' . DB::connection()->getQueryGrammar()->wrap($column), $bindings);
        }

        [$orderExpression, $orderBindings] = $this->caseExpression(
            array_map(
                static fn (array $row, int $index): array => ['id' => $row['id'], 'position' => $index],
                $rows,
                array_keys($rows),
            ),
            'position',
            count($rows),
        );

        return $query->orderByRaw($orderExpression, $orderBindings);
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

    /**
     * @param  array<string, int|string>  $defaults
     * @return array<int, string>
     */
    private function defaultSelects(array $defaults): array
    {
        return collect($defaults)
            ->map(fn (int|string $value, string $column): string => sprintf(
                '%s as %s',
                is_int($value) ? (string) $value : DB::connection()->getPdo()->quote($value),
                DB::connection()->getQueryGrammar()->wrap($column),
            ))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, int|string>>  $rows
     * @return array{0: string, 1: list<int|string>}
     */
    private function caseExpression(array $rows, string $column, int|string $defaultValue): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $idColumn = $grammar->wrap((new CuratorMedia)->getQualifiedKeyName());
        $bindings = [];
        $clauses = [];

        foreach ($rows as $row) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $clauses[] = 'when ? then ?';
            $bindings[] = (int) $row['id'];
            $bindings[] = $row[$column];
        }

        if ($clauses === []) {
            return ['?', [$defaultValue]];
        }

        $bindings[] = $defaultValue;

        return [
            sprintf('case %s %s else ? end', $idColumn, implode(' ', $clauses)),
            $bindings,
        ];
    }
}
