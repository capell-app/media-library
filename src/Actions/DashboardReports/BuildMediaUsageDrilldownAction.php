<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Data\MediaOwnerForeignKeyData;
use Capell\MediaLibrary\Data\MediaUsageReferenceData;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use stdClass;

/**
 * @method static list<MediaUsageReferenceData> run(int $mediaId, array<int, array{table: string, column: string}>|null $ownerForeignKeys = null, int $limit = 50)
 */
final class BuildMediaUsageDrilldownAction
{
    use AsAction;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return list<MediaUsageReferenceData>
     */
    public function handle(int $mediaId, ?array $ownerForeignKeys = null, int $limit = 50): array
    {
        if ($mediaId < 1 || $limit < 1) {
            return [];
        }

        $references = [];

        foreach (ResolveOwnerForeignKeysAction::run($ownerForeignKeys) as $ownerForeignKey) {
            $remaining = $limit - count($references);

            if ($remaining < 1) {
                break;
            }

            array_push(
                $references,
                ...$this->referencesForOwnerForeignKey($ownerForeignKey, $mediaId, $remaining),
            );
        }

        return $references;
    }

    /**
     * @return list<MediaUsageReferenceData>
     */
    private function referencesForOwnerForeignKey(MediaOwnerForeignKeyData $ownerForeignKey, int $mediaId, int $limit): array
    {
        if (! resolve(RuntimeSchemaState::class)->hasColumn($ownerForeignKey->table, 'id')) {
            return [];
        }

        $labelColumn = $this->labelColumn($ownerForeignKey->table);
        $selectColumns = ['id'];

        if ($labelColumn !== null) {
            $selectColumns[] = $labelColumn;
        }

        $references = DB::table($ownerForeignKey->table)
            ->where($ownerForeignKey->column, $mediaId)
            ->orderBy('id')
            ->limit($limit)
            ->get($selectColumns)
            ->map(fn (stdClass $record): MediaUsageReferenceData => new MediaUsageReferenceData(
                table: $ownerForeignKey->table,
                column: $ownerForeignKey->column,
                recordId: (string) $record->id,
                label: $this->recordLabel($record, $labelColumn),
            ))
            ->values()
            ->all();

        return array_values($references);
    }

    private function labelColumn(string $table): ?string
    {
        foreach (['name', 'title', 'slug'] as $column) {
            if (resolve(RuntimeSchemaState::class)->hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function recordLabel(stdClass $record, ?string $labelColumn): ?string
    {
        if ($labelColumn === null) {
            return null;
        }

        $label = $record->{$labelColumn} ?? null;

        return is_scalar($label) && (string) $label !== '' ? (string) $label : null;
    }
}
