<?php

declare(strict_types=1);

namespace Capell\MediaLibrary;

use Awcodes\Curator\Models\Media as BaseCuratorMedia;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Capell\MediaLibrary\Actions\EnsureMediaLibraryPermissionsAction;
use Capell\MediaLibrary\Console\MigrateSpatieToCuratorCommand;
use Capell\MediaLibrary\Filament\Components\CuratorMediaFieldFactory;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the Curator backend for Capell media:
 *   - capell.media.model  → CuratorMedia
 *   - capell.media.backend → 'curator'
 *   - MediaFieldFactory contract → CuratorMediaFieldFactory
 *   - MigrateSpatieToCuratorCommand (console only)
 */
final class MediaLibraryServiceProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    public static string $packageName = 'capell-app/media-library';

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'capell.media_library');

        $this->registerInstalledRuntime(self::$packageName);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'capell-media-library');

        if (CapellCore::isPackageInstalled(self::$packageName)) {
            $this->ensurePermissions();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => config_path('capell/media-library.php'),
            ], 'media-library-config');
        }
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->booted(function (): void {
            $this->registerMediaBackend();
            CapellCore::registerModels([CuratorMedia::class]);

            if (class_exists(CapellAdmin::class)) {
                CapellAdmin::registerExtensionPage(self::$packageName, MediaHealthPage::class);
            }

            if ($this->app->runningInConsole()) {
                $this->commands([MigrateSpatieToCuratorCommand::class]);

                if ($this->app instanceof Application && $this->app->isBooted()
                    && $this->app->bound('installed-runtime.initially-installed')
                    && $this->app->make('installed-runtime.initially-installed') === false) {
                    Artisan::registerCommand($this->app->make(MigrateSpatieToCuratorCommand::class));
                }
            }
        });
    }

    private function configPath(): string
    {
        return __DIR__ . '/../config/media-library.php';
    }

    private function ensurePermissions(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        if (is_string($table) && resolve(RuntimeSchemaState::class)->hasTable($table)) {
            EnsureMediaLibraryPermissionsAction::run();
        }
    }

    private function registerMediaBackend(): void
    {
        config()->set('capell.media.backend', 'curator');
        config()->set('capell.media.model', CuratorMedia::class);
        config()->set('curator.model', CuratorMedia::class);

        // Curator's interactive panel creates media rows via
        // App::make(Awcodes\Curator\Models\Media::class). Aliasing it to
        // CuratorMedia routes those rows through CuratorMedia's saved hook so
        // interactive SVG uploads are sanitized, not just trait-based ones.
        $this->app->bind(BaseCuratorMedia::class, CuratorMedia::class);

        $this->app->bind(MediaFieldFactory::class, CuratorMediaFieldFactory::class);
    }
}
