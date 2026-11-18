<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Builder<CuratorMedia> run(bool $useCache = true)
 */
final class BuildDuplicateMediaQueryAction
{
    use AsFake;
    use AsObject;

    /**
     * Builds the duplicate-media report query.
     *
     * Duplicates are found with one GROUP BY on the stored, indexed checksum, so
     * no blob is read in the admin request. Rows without a checksum are not
     * reported until `capell:media-backfill-checksums` has filled them in.
     *
     * @return Builder<CuratorMedia>
     */
    public function handle(bool $useCache = true): Builder
    {
        $schemaState = resolve(RuntimeSchemaState::class);

        if (! $schemaState->hasTable('curator') || ! $schemaState->hasColumn('curator', 'capell_checksum_sha256')) {
            return resolve(CuratorMediaQueryFactory::class)->emptyQuery($this->emptySelects());
        }

        if (! $useCache || $this->cacheTtlSeconds() < 1) {
            return $this->buildQueryFromRows($this->computeDuplicateRows());
        }

        /** @var list<array{id: int, duplicate_count: int, duplicate_hash: string}> $rows */
        $rows = Cache::remember(
            'capell-media-library:duplicates',
            $this->cacheTtlSeconds(),
            fn (): array => $this->computeDuplicateRows(),
        );

        return $this->buildQueryFromRows($rows);
    }

    /**
     * @param  list<array{id: int, duplicate_count: int, duplicate_hash: string}>  $rows
     * @return Builder<CuratorMedia>
     */
    private function buildQueryFromRows(array $rows): Builder
    {
        return resolve(CuratorMediaQueryFactory::class)->cachedReportRowsQuery($rows, [
            'duplicate_count' => 0,
            'duplicate_hash' => '',
        ]);
    }

    /**
     * @return list<array{id: int, duplicate_count: int, duplicate_hash: string}>
     */
    private function computeDuplicateRows(): array
    {
        $duplicateCounts = CuratorMedia::query()
            ->whereNotNull('capell_checksum_sha256')
            ->groupBy('capell_checksum_sha256')
            ->havingRaw('count(*) > 1')
            ->pluck(DB::raw('count(*)'), 'capell_checksum_sha256');

        if ($duplicateCounts->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach ($duplicateCounts->keys()->chunk(500) as $hashes) {
            CuratorMedia::query()
                ->select(['id', 'capell_checksum_sha256'])
                ->whereIn('capell_checksum_sha256', $hashes->all())
                ->orderBy('capell_checksum_sha256')
                ->orderBy('id')
                ->each(function (CuratorMedia $media) use (&$rows, $duplicateCounts): void {
                    $checksum = $media->getAttribute('capell_checksum_sha256');
                    $hash = is_string($checksum) ? $checksum : '';

                    $rows[] = [
                        'id' => $this->intValue($media->getKey()),
                        'duplicate_count' => $this->intValue($duplicateCounts[$hash] ?? 0),
                        'duplicate_hash' => $hash,
                    ];
                });
        }

        return $rows;
    }

    private function cacheTtlSeconds(): int
    {
        $ttlSeconds = config('capell.media_library.report_cache_ttl_seconds', 60);

        return is_numeric($ttlSeconds) ? max(0, (int) $ttlSeconds) : 60;
    }

    /**
     * @return array<int, string>
     */
    private function emptySelects(): array
    {
        return [
            '0 as duplicate_count',
            "'' as duplicate_hash",
        ];
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
