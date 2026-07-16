<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Console;

use Capell\MediaLibrary\Actions\MigrateSpatieMediaToCuratorAction;
use Capell\MediaLibrary\Data\MigrateSpatieMediaInput;
use Capell\MediaLibrary\Data\MigrateSpatieMediaResult;
use Illuminate\Console\Command;

/**
 * Artisan command that moves existing Spatie MediaLibrary rows into the
 * Curator single-FK model by populating per-collection FK columns on owner tables.
 *
 * Usage:
 *   php artisan capell:media-migrate-to-curator
 *   php artisan capell:media-migrate-to-curator --dry-run
 *   php artisan capell:media-migrate-to-curator --collection=image --collection=hero
 *   php artisan capell:media-migrate-to-curator --owner-type="App\Models\Post"
 *   php artisan capell:media-migrate-to-curator --chunk=500
 */
final class MigrateSpatieToCuratorCommand extends Command
{
    /** @var string */
    protected $signature = 'capell:media-migrate-to-curator
                            {--dry-run : Report what would happen without writing}
                            {--collection=* : Spatie collection names to migrate (repeatable; default: all)}
                            {--owner-type= : Restrict migration to this owner model FQCN}
                            {--chunk=200 : Number of Spatie media rows to process per chunk}';

    /** @var string */
    protected $description = 'Move existing Spatie media rows into the Curator backend by populating per-collection FK columns on owner tables.';

    public function handle(MigrateSpatieMediaToCuratorAction $action): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $chunkSize = (int) ($this->option('chunk') ?? 200);

        $ownerType = $this->option('owner-type');
        $collectionOption = $this->option('collection') ?? [];
        $collections = array_values(array_filter(
            is_array($collectionOption) ? $collectionOption : [],
            static fn (mixed $collection): bool => is_string($collection),
        ));

        $input = new MigrateSpatieMediaInput(
            dryRun: $isDryRun,
            collections: $collections,
            chunkSize: $chunkSize > 0 ? $chunkSize : 200,
            ownerType: is_string($ownerType) && $ownerType !== '' ? $ownerType : null,
        );

        if ($isDryRun) {
            $this->info($this->translation('commands.dry_run'));
        }

        $result = MigrateSpatieMediaToCuratorAction::run($input);

        $this->printSummary($result, $isDryRun);

        return self::SUCCESS;
    }

    private function printSummary(MigrateSpatieMediaResult $result, bool $isDryRun): void
    {
        $label = $isDryRun ? ' (dry run)' : '';

        $this->newLine();
        $this->line(sprintf('<fg=cyan>%s</>', $this->translation('commands.migration_summary', ['label' => $label])));
        $this->table(
            [$this->translation('commands.stat'), $this->translation('commands.count')],
            [
                [$this->translation('commands.processed'), $result->processed],
                [$this->translation('commands.created'), $result->created],
                [$this->translation('commands.skipped'), $result->skipped],
                [$this->translation('commands.owners_updated'), $result->ownersUpdated],
                [$this->translation('commands.warnings'), count($result->warnings)],
            ],
        );

        if ($result->warnings !== []) {
            $this->newLine();
            $this->warn($this->translation('commands.warnings_heading'));
            $this->table(
                [$this->translation('commands.warning_number'), $this->translation('commands.warning_message')],
                array_map(
                    static fn (int $index, string $message): array => [$index + 1, $message],
                    array_keys($result->warnings),
                    $result->warnings,
                ),
            );
        }
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $replace
     */
    private function translation(string $key, array $replace = []): string
    {
        $value = __('capell-media-library::package.' . $key, $replace);

        return is_string($value) ? $value : $key;
    }
}
