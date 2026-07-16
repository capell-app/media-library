<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class DiscoverOwnerForeignKeysAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<int, array{table: string, column: string}>
     */
    public function handle(): array
    {
        if (config('capell.media_library.auto_discover_owner_foreign_keys', true) !== true) {
            return [];
        }

        $candidateColumns = $this->candidateColumns();

        if ($candidateColumns === []) {
            return [];
        }

        $schema = Schema::getConnection()->getSchemaBuilder();

        return $this->discoverFromSchema($schema, $candidateColumns);
    }

    /**
     * @param  list<string>  $candidateColumns
     * @return array<int, array{table: string, column: string}>
     */
    private function discoverFromSchema(Builder $schema, array $candidateColumns): array
    {
        try {
            $tables = $schema->getTableListing(schemaQualified: false);
        } catch (Throwable) {
            return [];
        }

        $ownerForeignKeys = [];

        foreach ($tables as $table) {
            if (! $this->canInspectTable($table)) {
                continue;
            }

            foreach ($this->columnsForTable($schema, $table) as $column) {
                if (! in_array($column, $candidateColumns, true)) {
                    continue;
                }

                $ownerForeignKeys[] = [
                    'table' => $table,
                    'column' => $column,
                ];
            }
        }

        return $ownerForeignKeys;
    }

    /**
     * @return list<string>
     */
    private function candidateColumns(): array
    {
        $configured = config('capell.media_library.owner_foreign_key_columns', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $column): string => is_string($column) ? trim($column) : '', $configured),
            fn (string $column): bool => $column !== '' && $this->isSafeIdentifier($column),
        )));
    }

    /**
     * @return list<string>
     */
    private function columnsForTable(Builder $schema, string $table): array
    {
        try {
            return array_values(array_filter(
                $schema->getColumnListing($table),
                $this->isSafeIdentifier(...),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    private function canInspectTable(string $table): bool
    {
        return $table !== 'curator'
            && $this->isSafeIdentifier($table);
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^\w+$/', $identifier) === 1;
    }
}
