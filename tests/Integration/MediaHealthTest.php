<?php

declare(strict_types=1);

use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\MediaLibrary\Filament\Pages\Tables\MediaHealthTable;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('media_health_query_uses_curator_rows_and_known_owner_foreign_keys', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
        ['table' => 'test_curator_owners', 'column' => 'thumbnail_id'],
    ]);

    $healthyMediaId = insertCuratorHealthMedia('healthy', 'Useful alt text', now());
    $missingAltMediaId = insertCuratorHealthMedia('missing-alt', null, now());
    $thumbnailMediaId = insertCuratorHealthMedia('thumbnail', 'Thumbnail alt text', now());
    $unusedMediaId = insertCuratorHealthMedia('unused', 'Unused alt text', now());
    $staleMediaId = insertCuratorHealthMedia('stale', 'Stale alt text', now()->subDays(91));

    TestCuratorOwner::query()->create(['name' => 'Healthy Owner', 'image_id' => $healthyMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Missing Alt Owner', 'image_id' => $missingAltMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Thumbnail Owner', 'thumbnail_id' => $thumbnailMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Stale Owner', 'image_id' => $staleMediaId]);

    $records = BuildMediaHealthQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->not->toContain($healthyMediaId, $thumbnailMediaId);
    expect($records->keys()->all())->toContain($missingAltMediaId, $unusedMediaId, $staleMediaId);
    expect((int) $records->get($missingAltMediaId)->usage_count)->toBe(1);
    expect($records->get($missingAltMediaId)->getAttribute('media_health_issue'))->toBe('missing_alt');
    expect((int) $records->get($unusedMediaId)->usage_count)->toBe(0);
    expect($records->get($unusedMediaId)->getAttribute('media_health_issue'))->toBe('unused');
    expect($records->get($staleMediaId)->getAttribute('media_health_issue'))->toBe('stale');
});

test('media health query uses the configured stale threshold', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    $staleMediaId = insertCuratorHealthMedia('configured-stale', 'Useful alt text', now()->subDays(31));

    TestCuratorOwner::query()->create(['name' => 'Configured Stale Owner', 'image_id' => $staleMediaId]);

    $records = BuildMediaHealthQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->toContain($staleMediaId)
        ->and($records->get($staleMediaId)->getAttribute('media_health_issue'))->toBe('stale');
});

test('media health table issue filter matches computed issue labels', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    $healthyMediaId = insertCuratorHealthMedia('filter-healthy', 'Useful alt text', now());
    $missingAltMediaId = insertCuratorHealthMedia('filter-missing-alt', null, now());
    $staleMediaId = insertCuratorHealthMedia('filter-stale-unused', 'Stale alt text', now()->subDays(31));
    $unusedMediaId = insertCuratorHealthMedia('filter-unused', 'Unused alt text', now());

    TestCuratorOwner::query()->create(['name' => 'Healthy Owner', 'image_id' => $healthyMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Missing Alt Owner', 'image_id' => $missingAltMediaId]);

    $filter = MediaHealthTable::configure(mediaHealthTableHarness())->getFilters()['media_health_issue'];
    $missingAltQuery = BuildMediaHealthQueryAction::run();
    $staleQuery = BuildMediaHealthQueryAction::run();
    $unusedQuery = BuildMediaHealthQueryAction::run();

    $filter->apply($missingAltQuery, ['value' => 'missing_alt']);
    $filter->apply($staleQuery, ['value' => 'stale']);
    $filter->apply($unusedQuery, ['value' => 'unused']);

    expect($missingAltQuery->pluck('id')->all())->toBe([$missingAltMediaId])
        ->and($staleQuery->pluck('id')->all())->toBe([$staleMediaId])
        ->and($unusedQuery->pluck('id')->all())->toBe([$unusedMediaId]);
});

test('media_health_query_is_empty_when_curator_table_has_not_been_installed', function (): void {
    Schema::dropIfExists('curator');

    expect(BuildMediaHealthQueryAction::run()->get())->toHaveCount(0);
});

test('media health page registers as an extension page', function (): void {
    app()->singleton(ExtensionPageRegistry::class, fn (): ExtensionPageRegistry => new ExtensionPageRegistry);
    app()->singleton(CapellAdminManager::class, fn (): CapellAdminManager => new CapellAdminManager);

    resolve(CapellAdminManager::class)->registerExtensionPage(
        'capell-app/media-library',
        MediaHealthPage::class,
    );

    $extensionPage = collect(resolve(ExtensionPageRegistry::class)->entries())
        ->first(fn (array $extensionPage): bool => $extensionPage['page'] === MediaHealthPage::class);

    expect($extensionPage['page'] ?? null)->toBe(MediaHealthPage::class);
});

function insertCuratorHealthMedia(string $name, ?string $alt, DateTimeInterface $updatedAt): int
{
    return DB::table('curator')->insertGetId([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => $name,
        'path' => 'media/' . $name . '.jpg',
        'width' => 800,
        'height' => 600,
        'size' => 10000,
        'type' => 'image/jpeg',
        'ext' => 'jpg',
        'alt' => $alt,
        'title' => null,
        'description' => null,
        'caption' => null,
        'exif' => null,
        'curations' => null,
        'created_at' => now(),
        'updated_at' => $updatedAt,
    ]);
}

function mediaHealthTableHarness(): Table
{
    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('makeFilamentTranslatableContentDriver')->andReturn(null);

    return Table::make($livewire);
}
