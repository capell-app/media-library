<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Providers;

use Capell\Core\Support\Migration\MigrationRootRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class MigrationMetadataServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->callAfterResolving(MigrationRootRegistry::class, static function (MigrationRootRegistry $registry): void {
            $registry->register('capell-app/media-library', __DIR__ . '/../../database/migrations', [
                '2026_11_14_000002_create_curator_structured_usages_table' => 'curator_structured_usages',
            ]);
        });
    }
}
