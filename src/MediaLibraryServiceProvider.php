<?php

declare(strict_types=1);

namespace Capell\MediaLibrary;

use Awcodes\Curator\Actions\MultiUploadAction;
use Awcodes\Curator\Config\CuratorManager;
use Awcodes\Curator\Facades\Curator;
use Awcodes\Curator\Models\Media as BaseCuratorMedia;
use Awcodes\Curator\Resources\Media\MediaResource as BaseCuratorResource;
use Awcodes\Curator\Resources\Media\Schemas\MediaForm;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Capell\MediaLibrary\Actions\DashboardReports\DiscoverOwnerForeignKeysAction;
use Capell\MediaLibrary\Actions\EnsureMediaLibraryPermissionsAction;
use Capell\MediaLibrary\Console\BackfillMediaChecksumsCommand;
use Capell\MediaLibrary\Console\MigrateSpatieToCuratorCommand;
use Capell\MediaLibrary\Console\PurgeQuarantinedMediaCommand;
use Capell\MediaLibrary\Console\RebuildStructuredMediaUsageCommand;
use Capell\MediaLibrary\Filament\Actions\CuratorMultiUploadAction;
use Capell\MediaLibrary\Filament\Components\CuratorMediaFieldFactory;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\MediaLibrary\Filament\Resources\CuratorMediaResource;
use Capell\MediaLibrary\Filament\Schemas\CuratorMediaForm;
use Capell\MediaLibrary\Listeners\SyncStructuredMediaUsageOnModelWrite;
use Capell\MediaLibrary\Livewire\CuratorPanel;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Policies\CuratorMediaPolicy;
use Capell\MediaLibrary\Support\CuratorMediaListContext;
use Capell\MediaLibrary\Support\CuratorMediaSiteAccess;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Override;
use ReflectionProperty;
use RuntimeException;

/**
 * Registers the Curator backend for Capell media:
 *   - capell.media.model  → CuratorMedia
 *   - capell.media.backend → 'curator'
 *   - MediaFieldFactory contract → CuratorMediaFieldFactory
 *   - MigrateSpatieToCuratorCommand, PurgeQuarantinedMediaCommand (console only)
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
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'capell-media-library');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => config_path('capell/media-library.php'),
            ], 'media-library-config');
        }
    }

    protected function bootInstalledRuntime(): void
    {
        $this->ensurePermissions();
        $this->registerMediaBackend();
        $this->app->booted(static fn () => Livewire::component('curator-panel', CuratorPanel::class));
        CapellCore::registerModels([CuratorMedia::class]);
        $this->app->scoped(CuratorMediaSiteAccess::class);
        $this->app->scoped(CuratorMediaListContext::class);
        Gate::policy(CuratorMedia::class, CuratorMediaPolicy::class);

        if (class_exists(CapellAdmin::class)) {
            CapellAdmin::registerExtensionPage(self::$packageName, MediaHealthPage::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateSpatieToCuratorCommand::class,
                PurgeQuarantinedMediaCommand::class,
                BackfillMediaChecksumsCommand::class,
                RebuildStructuredMediaUsageCommand::class,
            ]);
        }

        // Owner foreign keys are discovered from the schema, so a migration invalidates the remembered result.
        Event::listen(MigrationsEnded::class, DiscoverOwnerForeignKeysAction::flush(...));
        $this->app->scoped(SyncStructuredMediaUsageOnModelWrite::class);
        Event::listen(['eloquent.saved: *', 'eloquent.deleted: *'], static function (string $event, array $payload): void {
            resolve(SyncStructuredMediaUsageOnModelWrite::class)->handle($event, $payload);
        });

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('capell:media-purge-quarantined')
                ->dailyAt('03:15')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('capell:media-rebuild-structured-usage')
                ->dailyAt('03:45')
                ->withoutOverlapping()
                ->onOneServer();
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

        // Curator has no public accessor for its explicit (pre-fallback) settings.
        // Only install fallbacks when the host has not configured the facade.
        $manager = Curator::getFacadeRoot();
        throw_unless($manager instanceof CuratorManager, RuntimeException::class);
        if (new ReflectionProperty($manager, 'diskName')->getValue($manager) === null) {
            Curator::disk(static function (): string {
                $disk = config('capell.media_library.default_disk');
                $curatorDisk = config('curator.default_disk');
                // Curator inherits FILESYSTEM_DISK even when no interactive disk
                // was selected. Only an explicit Curator choice beats public.
                if ($curatorDisk !== config('capell.media_library.inherited_filesystem_disk')) {
                    $disk ??= $curatorDisk;
                }

                return is_string($disk) && $disk !== '' ? $disk : 'public';
            });
        }

        if (new ReflectionProperty($manager, 'visibility')->getValue($manager) === null) {
            Curator::visibility(static function (): string {
                $visibility = config('capell.media_library.default_visibility');
                $visibility ??= config('curator.default_visibility');

                return $visibility === 'private' ? 'private' : 'public';
            });
        }

        if (config('curator.resource.resource') === BaseCuratorResource::class) {
            config()->set('curator.resource.resource', CuratorMediaResource::class);
        }

        if (config('curator.resource.schemas.form') === MediaForm::class) {
            config()->set('curator.resource.schemas.form', CuratorMediaForm::class);
        }

        $this->app->bind(MultiUploadAction::class, CuratorMultiUploadAction::class);

        // Curator's interactive panel creates media rows via
        // App::make(Awcodes\Curator\Models\Media::class). Aliasing it to
        // CuratorMedia routes those rows through CuratorMedia's created hook so
        // interactive SVG uploads are sanitized, not just trait-based ones.
        $this->app->bind(BaseCuratorMedia::class, CuratorMedia::class);

        $this->app->bind(MediaFieldFactory::class, CuratorMediaFieldFactory::class);
    }
}
