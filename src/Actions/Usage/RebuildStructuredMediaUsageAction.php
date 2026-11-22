<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\Usage;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Support\UsageResolvers\StructuredColumnUsageResolver;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Rebuilds the structured usage index from every configured column, catching writes that bypassed model events and
 * removing rows whose source record or table is gone.
 */
final class RebuildStructuredMediaUsageAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly StructuredColumnUsageResolver $resolver) {}

    /** @return int The number of source records indexed. */
    public function handle(): int
    {
        $usageTable = SyncStructuredMediaUsageAction::table();

        if (! resolve(RuntimeSchemaState::class)->hasTable($usageTable)) {
            return 0;
        }

        $tables = [];

        foreach ($this->resolver->referenceColumns() as [$table, $column]) {
            $tables[$table][] = $column;
        }

        DB::table($usageTable)->whereNotIn('source_table', array_keys($tables))->delete();
        $count = 0;

        foreach ($tables as $table => $columns) {
            DB::table($usageTable)
                ->where('source_table', $table)
                ->whereNotExists(static fn ($source) => $source->select(DB::raw('1'))->from($table)->whereColumn($table . '.id', $usageTable . '.source_id'))
                ->delete();

            DB::table($table)->select('id')->where(static function ($query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhereNotNull($column);
                }
            })->orderBy('id')->chunkById(500, function ($rows) use ($table, &$count): void {
                foreach ($rows as $row) {
                    if (is_numeric($row->id ?? null)) {
                        SyncStructuredMediaUsageAction::run($table, (int) $row->id);
                        $count++;
                    }
                }
            });
        }

        return $count;
    }
}
