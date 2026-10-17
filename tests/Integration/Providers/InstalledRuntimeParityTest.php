<?php

declare(strict_types=1);

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Facades\CapellCore;
use Capell\Frontend\Support\Cache\FrontendCacheInvalidationObserver;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\Tests\Support\InstalledRuntimeParity;
use Capell\Tests\Support\InstalledRuntimeState;
use Capell\Tests\Support\InstalledRuntimeTestCase;
use Illuminate\Contracts\Events\Dispatcher;

it('matches fresh installed boot after in-process installation and repeated refresh', function (): void {
    InstalledRuntimeParity::assertPackage('media-library');
});

it('keeps the Curator cache observers identical on fresh boot and late installation', function (): void {
    InstalledRuntimeState::run(static function (): void {
        $manifest = InstalledRuntimeParity::manifest('media-library');
        $fresh = InstalledRuntimeTestCase::boot($manifest, true, sourceRoot: dirname(__DIR__, 3));
        $fresh->make(CuratorMedia::class);

        $expected = $fresh->make(Dispatcher::class)->getRawListeners();
        foreach (['saved', 'deleted', 'restored'] as $event) {
            $listeners = $expected['eloquent.' . $event . ': ' . CuratorMedia::class] ?? [];
            throw_unless(is_array($listeners), RuntimeException::class);
            expect(array_filter($listeners, static fn (mixed $listener): bool => $listener === FrontendCacheInvalidationObserver::class . '@' . $event))->toHaveCount(1);
        }

        $late = InstalledRuntimeTestCase::boot($manifest, false, sourceRoot: dirname(__DIR__, 3));
        InstalledRuntimeTestCase::prepareLateInstallMigrations($late);
        $package = CapellCore::getPackage('capell-app/media-library');
        InstallPackageAction::run($package);
        RefreshInstalledPackageRuntimeAction::run($package);
        RefreshInstalledPackageRuntimeAction::run($package);
        $late->make(CuratorMedia::class);
        $actual = $late->make(Dispatcher::class)->getRawListeners();
        foreach (['saved', 'deleted', 'restored'] as $event) {
            $key = 'eloquent.' . $event . ': ' . CuratorMedia::class;
            expect($actual[$key] ?? [])->toBe($expected[$key]);
        }
    });
});
