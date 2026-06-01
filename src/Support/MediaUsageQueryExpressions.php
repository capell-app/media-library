<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Data\MediaOwnerForeignKeyData;
use Illuminate\Support\Facades\DB;

final class MediaUsageQueryExpressions
{
    /**
     * @return array<int, MediaOwnerForeignKeyData>
     */
    public function knownOwnerForeignKeys(mixed $configuredOwnerForeignKeys): array
    {
        if (! is_array($configuredOwnerForeignKeys)) {
            return [];
        }

        $ownerForeignKeys = [];

        foreach ($configuredOwnerForeignKeys as $configuredOwnerForeignKey) {
            if (! is_array($configuredOwnerForeignKey)) {
                continue;
            }

            $table = $configuredOwnerForeignKey['table'] ?? null;
            $column = $configuredOwnerForeignKey['column'] ?? null;
            if (! is_string($table)) {
                continue;
            }

            if (! is_string($column)) {
                continue;
            }

            if (! $this->isSafeIdentifier($table)) {
                continue;
            }

            if (! $this->isSafeIdentifier($column)) {
                continue;
            }

            if (! resolve(RuntimeSchemaState::class)->hasTable($table)) {
                continue;
            }

            if (! resolve(RuntimeSchemaState::class)->hasColumn($table, $column)) {
                continue;
            }

            $ownerForeignKeys[] = new MediaOwnerForeignKeyData(
                table: $table,
                column: $column,
            );
        }

        return $ownerForeignKeys;
    }

    /**
     * @param  array<int, MediaOwnerForeignKeyData>  $ownerForeignKeys
     */
    public function usageCountExpression(array $ownerForeignKeys, string $curatorTableAlias = 'curator'): string
    {
        if ($ownerForeignKeys === []) {
            return '0';
        }

        $grammar = DB::connection()->getQueryGrammar();
        $curatorIdColumn = $grammar->wrap($curatorTableAlias . '.id');
        $usageQueries = [];

        foreach ($ownerForeignKeys as $ownerForeignKey) {
            $usageQueries[] = sprintf(
                '(select count(*) from %s where %s = %s)',
                $grammar->wrapTable($ownerForeignKey->table),
                $grammar->wrap($ownerForeignKey->column),
                $curatorIdColumn,
            );
        }

        return implode(' + ', $usageQueries);
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^\w+$/', $identifier) === 1;
    }
}
