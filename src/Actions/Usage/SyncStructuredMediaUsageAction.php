<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\Usage;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Support\UsageResolvers\StructuredColumnUsageResolver;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Rewrites the usage index rows for one stored record, so media referenced only from its structured JSON (layout
 * containers, block content, settings) is attributed to the record's site. List and picker queries read this index,
 * because the JSON itself cannot be matched in SQL. A record that no longer exists loses its rows.
 *
 * @method static void run(string $table, int $id)
 */
final class SyncStructuredMediaUsageAction
{
    use AsFake;
    use AsObject;

    public const string TABLE = 'curator_structured_usages';

    public function __construct(private readonly StructuredColumnUsageResolver $resolver) {}

    public function handle(string $table, int $id): void
    {
        $schema = resolve(RuntimeSchemaState::class);

        if (! $schema->hasTable(self::TABLE)) {
            return;
        }

        $columns = [];

        foreach ($this->resolver->referenceColumns() as [$resolvedTable, $column]) {
            if ($resolvedTable === $table) {
                $columns[] = $column;
            }
        }

        if ($columns === []) {
            return;
        }

        $extra = array_values(array_filter(
            ['site_id', 'translatable_type', 'translatable_id'],
            static fn (string $name): bool => $schema->hasColumn($table, $name),
        ));
        $row = DB::table($table)->select(['id', ...$columns, ...$extra])->where('id', $id)->first();
        $mediaIds = [];

        foreach ($row === null ? [] : $columns as $column) {
            $value = $row->{$column} ?? null;

            foreach (is_string($value) ? $this->resolver->mediaIdsIn($value) : [] as $mediaId) {
                $mediaIds[$mediaId] = $mediaId;
            }
        }

        $siteId = $row === null || $mediaIds === [] ? null : $this->resolver->siteOfRow($table, $row);
        $now = now();

        DB::transaction(static function () use ($table, $id, $mediaIds, $siteId, $now): void {
            DB::table(self::TABLE)->where('source_table', $table)->where('source_id', $id)->delete();

            if ($mediaIds === []) {
                return;
            }

            DB::table(self::TABLE)->insert(array_map(static fn (int $mediaId): array => [
                'media_id' => $mediaId,
                'site_id' => $siteId,
                'source_table' => $table,
                'source_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values($mediaIds)));
        });
    }
}
