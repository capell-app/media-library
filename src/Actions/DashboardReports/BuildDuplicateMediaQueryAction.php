<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * @method static Builder<CuratorMedia> run(bool $useCache = true)
 */
final class BuildDuplicateMediaQueryAction
{
    use AsAction;

    /**
     * Builds the duplicate-media report query.
     *
     * Hashing reads each blob off disk, so the computed result is cached for
     * the configured TTL rather than re-hashed on every request. Files are
     * streamed (hashStorageFile) and processed in bounded chunks to keep
     * memory flat on large libraries.
     *
     * NOTE: the durable fix is a persisted content_hash column on the curator
     * table, populated on upload (and backfilled via a one-time command), so
     * duplicates can be found with a single GROUP BY and no disk reads. That is
     * a larger schema change; this action eliminates the unbounded synchronous
     * full-library read on the request path in the meantime by chunking the
     * scan and caching the result.
     *
     * @return Builder<CuratorMedia>
     */
    public function handle(bool $useCache = true): Builder
    {
        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
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
        /** @var Collection<int, array{id: int, duplicate_hash: string}> $candidates */
        $candidates = new Collection;

        CuratorMedia::query()
            ->select(['id', 'disk', 'path'])
            ->whereNotNull('path')
            ->where('path', '<>', '')
            ->orderBy('id')
            ->chunk(200, function (Collection $mediaChunk) use ($candidates): void {
                foreach ($mediaChunk as $media) {
                    $candidateRow = $this->duplicateCandidateRow($media);

                    if ($candidateRow !== null) {
                        $candidates->push($candidateRow);
                    }
                }
            });

        return array_values($candidates
            ->groupBy('duplicate_hash')
            ->filter(static fn (Collection $duplicateRows): bool => $duplicateRows->count() > 1)
            ->flatMap(static function (Collection $duplicateRows, string $duplicateHash): array {
                $duplicateCount = $duplicateRows->count();

                return $duplicateRows
                    ->sortBy('id')
                    ->map(static fn (array $row): array => [
                        'id' => $row['id'],
                        'duplicate_count' => $duplicateCount,
                        'duplicate_hash' => $duplicateHash,
                    ])
                    ->values()
                    ->all();
            })
            ->sortBy([
                ['duplicate_hash', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->all());
    }

    private function cacheTtlSeconds(): int
    {
        $ttlSeconds = config('capell.media_library.report_cache_ttl_seconds', 60);

        return is_numeric($ttlSeconds) ? max(0, (int) $ttlSeconds) : 60;
    }

    /**
     * @return array{id: int, duplicate_hash: string}|null
     */
    private function duplicateCandidateRow(CuratorMedia $media): ?array
    {
        $hash = $this->contentHash($media);

        if ($hash === null) {
            return null;
        }

        return [
            'id' => $this->intValue($media->getKey()),
            'duplicate_hash' => $hash,
        ];
    }

    private function contentHash(CuratorMedia $media): ?string
    {
        $disk = $media->getAttribute('disk');
        $path = $media->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return null;
        }

        try {
            $storageDisk = Storage::disk($disk);

            if (! $storageDisk->exists($path)) {
                return null;
            }

            return $this->hashStorageFile($storageDisk, $path);
        } catch (Throwable) {
            return null;
        }
    }

    private function hashStorageFile(Filesystem $storageDisk, string $path): ?string
    {
        $stream = $storageDisk->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $hashContext = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    return null;
                }

                hash_update($hashContext, $chunk);
            }

            return hash_final($hashContext);
        } finally {
            fclose($stream);
        }
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
