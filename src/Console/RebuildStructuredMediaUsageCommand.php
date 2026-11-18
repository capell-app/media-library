<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Console;

use Capell\MediaLibrary\Actions\Usage\RebuildStructuredMediaUsageAction;
use Illuminate\Console\Command;

/**
 * Usage:
 *   php artisan capell:media-rebuild-structured-usage
 */
final class RebuildStructuredMediaUsageCommand extends Command
{
    /** @var string */
    protected $signature = 'capell:media-rebuild-structured-usage';

    /** @var string */
    protected $description = 'Rebuild the index of media referenced from layout, block and settings JSON, so the media picker shows it only to its sites.';

    public function handle(RebuildStructuredMediaUsageAction $rebuild): int
    {
        $count = $rebuild->handle();

        $this->info(trans_choice('capell-media-library::package.commands.structured_usage_rebuilt', $count, ['count' => $count]));

        return self::SUCCESS;
    }
}
