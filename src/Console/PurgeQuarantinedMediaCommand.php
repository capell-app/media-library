<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Console;

use Capell\MediaLibrary\Actions\PurgeQuarantinedMediaAction;
use Illuminate\Console\Command;

/**
 * Permanently removes media that has been in quarantine for longer than the
 * retention period and is still unreferenced.
 *
 * Usage:
 *   php artisan capell:media-purge-quarantined
 *   php artisan capell:media-purge-quarantined --days=7
 */
final class PurgeQuarantinedMediaCommand extends Command
{
    /** @var string */
    protected $signature = 'capell:media-purge-quarantined
                            {--days= : Retention in days (default: capell.media_library.quarantine_retention_days)}';

    /** @var string */
    protected $description = 'Permanently delete quarantined media (and its files) once the retention period has passed and it is still unused.';

    public function handle(): int
    {
        $days = $this->option('days');
        $result = PurgeQuarantinedMediaAction::run(is_numeric($days) ? max(0, (int) $days) : null);

        $this->table(
            [__('capell-media-library::package.commands.stat'), __('capell-media-library::package.commands.count')],
            [
                [__('capell-media-library::package.commands.purged'), count($result->purgedIds)],
                [__('capell-media-library::package.commands.restored'), count($result->restoredIds)],
                [__('capell-media-library::package.commands.failed_blob_deletes'), count($result->failedBlobDeletes)],
            ],
        );

        if ($result->failedBlobDeletes === []) {
            return self::SUCCESS;
        }

        $this->error(__('capell-media-library::package.commands.failed_blob_deletes_heading'));
        $this->table(
            ['ID', 'Disk', 'Path', __('capell-media-library::package.commands.warning_message')],
            array_map(
                static fn (array $failure): array => [$failure['media_id'], $failure['disk'], $failure['path'], $failure['error']],
                $result->failedBlobDeletes,
            ),
        );

        return self::FAILURE;
    }
}
