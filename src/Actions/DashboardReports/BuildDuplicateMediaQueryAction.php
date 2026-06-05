<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaQueryFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class BuildDuplicateMediaQueryAction
{
    use AsAction;

    /**
     * @return Builder<CuratorMedia>
     */
    public function handle(): Builder
    {
        if (! resolve(RuntimeSchemaState::class)->hasTable('curator')) {
            return resolve(CuratorMediaQueryFactory::class)->emptyQuery($this->emptySelects());
        }

        /** @var Collection<int, CuratorMedia> $media */
        $media = CuratorMedia::query()
            ->select(['id', 'disk', 'path'])
            ->whereNotNull('path')
            ->where('path', '<>', '')
            ->get();

        $rows = $media
            ->map(fn (CuratorMedia $media): ?array => $this->duplicateCandidateRow($media))
            ->filter()
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
            ->all();

        return resolve(CuratorMediaQueryFactory::class)->cachedReportRowsQuery($rows, [
            'duplicate_count' => 0,
            'duplicate_hash' => '',
        ]);
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
            'id' => (int) $media->getKey(),
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
}
