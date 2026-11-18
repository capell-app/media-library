<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Console;

use Capell\MediaLibrary\Jobs\CalculateMediaChecksumJob;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Queues a checksum job for every media row that has none, so the duplicates report
 * covers files uploaded before checksums were stored.
 *
 * Usage:
 *   php artisan capell:media-backfill-checksums
 *   php artisan capell:media-backfill-checksums --sync
 */
final class BackfillMediaChecksumsCommand extends Command
{
    /** @var string */
    protected $signature = 'capell:media-backfill-checksums
                            {--sync : Calculate the checksums in this process instead of queueing jobs}';

    /** @var string */
    protected $description = 'Calculate the stored checksum of media that has none, so the duplicates report can find it.';

    public function handle(): int
    {
        if (! Schema::hasColumn('curator', 'capell_checksum_sha256')) {
            $this->error(__('capell-media-library::package.commands.checksum_column_missing'));

            return self::FAILURE;
        }

        $count = 0;

        CuratorMedia::query()
            ->whereNull('capell_checksum_sha256')
            ->whereNotNull('path')
            ->where('path', '<>', '')
            ->select(['id'])
            ->chunkById(200, function ($chunk) use (&$count): void {
                foreach ($chunk as $media) {
                    $key = $media->getKey();

                    if (! is_int($key) && ! is_string($key)) {
                        continue;
                    }

                    $this->option('sync')
                        ? dispatch_sync(new CalculateMediaChecksumJob($key))
                        : dispatch(new CalculateMediaChecksumJob($key));
                    $count++;
                }
            });

        $this->info(trans_choice('capell-media-library::package.commands.checksums_queued', $count, ['count' => $count]));

        return self::SUCCESS;
    }
}
