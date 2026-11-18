<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class DiscoverOwnerForeignKeysAction
{
    use AsFake;
    use AsObject;

    private const string CACHE_KEY = 'capell-media-library:owner-foreign-keys';

    /**
     * Discovery results for this process, keyed by the candidate column list.
     *
     * @var array<string, array<int, array{table: string, column: string}>>
     */
    private static array $resolved = [];

    /**
     * Drops the remembered discovery so the next call re-reads the schema. Called after
     * migrations and by tests that change the schema or the candidate columns.
     */
    public static function flush(): void
    {
        self::$resolved = [];
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  bool  $force  Discover even when auto-discovery is disabled. Destructive callers
     *                       use this so an opt-out of reporting never hides a referencing column.
     * @return array<int, array{table: string, column: string}>
     */
    public function handle(bool $force = false): array
    {
        if (! $force && config('capell.media_library.auto_discover_owner_foreign_keys', true) !== true) {
            return [];
        }

        $candidateColumns = $this->candidateColumns();

        if ($candidateColumns === []) {
            return [];
        }

        $key = hash('xxh128', implode(',', $candidateColumns) . '|' . Schema::getConnection()->getDatabaseName());

        if (self::$resolved === []) {
            $stored = Cache::get(self::CACHE_KEY);

            if (is_array($stored)) {
                /** @var array<string, array<int, array{table: string, column: string}>> $stored */
                self::$resolved = $stored;
            }
        }

        if (isset(self::$resolved[$key])) {
            return self::$resolved[$key];
        }

        $discovered = $this->discoverFromSchema(Schema::getConnection()->getSchemaBuilder(), $candidateColumns);

        // An unreadable schema returns [] and must be retried, so only a real discovery is remembered.
        if ($discovered !== []) {
            self::$resolved[$key] = $discovered;
            Cache::forever(self::CACHE_KEY, self::$resolved);
        }

        return $discovered;
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
