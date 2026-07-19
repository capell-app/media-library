<?php

declare(strict_types=1);

use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMissingAltMediaQueryAction;
use Capell\MediaLibrary\Actions\DispatchMissingAltMediaSignalsAction;
use Capell\MediaLibrary\Events\MediaMissingAltDetected;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\MediaLibrary\Filament\Pages\Tables\MediaHealthTable;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    $missingAltMedia = mediaHealthRecord($records, $missingAltMediaId);
    $unusedMedia = mediaHealthRecord($records, $unusedMediaId);
    $staleMedia = mediaHealthRecord($records, $staleMediaId);

    expect(mediaHealthIntAttribute($missingAltMedia, 'usage_count'))->toBe(1);
    expect($missingAltMedia->getAttribute('media_health_issue'))->toBe('missing_alt');
    expect(mediaHealthIntAttribute($unusedMedia, 'usage_count'))->toBe(0);
    expect($unusedMedia->getAttribute('media_health_issue'))->toBe('unused');
    expect($staleMedia->getAttribute('media_health_issue'))->toBe('stale');
});

test('media health query discovers conventional owner foreign keys by default', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', []);

    $healthyMediaId = insertCuratorHealthMedia('discovered-healthy', 'Useful alt text', now());
    $unusedMediaId = insertCuratorHealthMedia('discovered-unused', 'Unused alt text', now());

    TestCuratorOwner::query()->create(['name' => 'Discovered Owner', 'image_id' => $healthyMediaId]);

    $records = BuildMediaHealthQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->toContain($unusedMediaId)
        ->and($records->keys()->all())->not->toContain($healthyMediaId)
        ->and(mediaHealthIntAttribute(mediaHealthRecord($records, $unusedMediaId), 'usage_count'))->toBe(0)
        ->and(mediaHealthRecord($records, $unusedMediaId)->getAttribute('media_health_issue'))->toBe('unused');
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
        ->and(mediaHealthRecord($records, $staleMediaId)->getAttribute('media_health_issue'))->toBe('stale');
});

test('media health query rebuilds a query from cached report rows', function (): void {
    Cache::flush();
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $firstMissingAltMediaId = insertCuratorHealthMedia('cached-missing-alt-first', null, now());
    TestCuratorOwner::query()->create(['name' => 'Cached Owner', 'image_id' => $firstMissingAltMediaId]);

    $firstRecords = BuildMediaHealthQueryAction::run()->get()->keyBy('id');

    $secondMissingAltMediaId = insertCuratorHealthMedia('cached-missing-alt-second', null, now());
    TestCuratorOwner::query()->create(['name' => 'Second Cached Owner', 'image_id' => $secondMissingAltMediaId]);

    $cachedRecords = BuildMediaHealthQueryAction::run()->get()->keyBy('id');
    $liveRecords = BuildMediaHealthQueryAction::run(null, false)->get()->keyBy('id');

    expect($firstRecords->keys()->all())->toBe([$firstMissingAltMediaId])
        ->and($cachedRecords->keys()->all())->toBe([$firstMissingAltMediaId])
        ->and($liveRecords->keys()->all())->toContain($firstMissingAltMediaId, $secondMissingAltMediaId)
        ->and(mediaHealthIntAttribute(mediaHealthRecord($cachedRecords, $firstMissingAltMediaId), 'usage_count'))->toBe(1)
        ->and(mediaHealthRecord($cachedRecords, $firstMissingAltMediaId)->getAttribute('media_health_issue'))->toBe('missing_alt');
});

test('missing alt media query exposes image candidates with usage counts', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $usedMissingAltMediaId = insertCuratorSignalMedia('used-missing-alt', null, 'image/jpeg');
    $unusedMissingAltMediaId = insertCuratorSignalMedia('unused-missing-alt', '   ', 'image/jpeg');
    $completeMediaId = insertCuratorSignalMedia('complete-alt', 'Useful alt text', 'image/jpeg');
    $documentMediaId = insertCuratorSignalMedia('document-missing-alt', null, 'application/pdf');

    TestCuratorOwner::query()->create(['name' => 'Used Missing Alt Owner', 'image_id' => $usedMissingAltMediaId]);

    $records = BuildMissingAltMediaQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->toBe([$usedMissingAltMediaId, $unusedMissingAltMediaId])
        ->and($records->keys()->all())->not->toContain($completeMediaId, $documentMediaId)
        ->and(mediaHealthIntAttribute(mediaHealthRecord($records, $usedMissingAltMediaId), 'usage_count'))->toBe(1)
        ->and(mediaHealthIntAttribute(mediaHealthRecord($records, $unusedMissingAltMediaId), 'usage_count'))->toBe(0)
        ->and(mediaHealthRecord($records, $usedMissingAltMediaId)->getAttribute('media_missing_alt_signal'))->toBe('missing_alt');
});

test('missing alt signal dispatch action emits prioritized media events', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $usedMissingAltMediaId = insertCuratorSignalMedia('signal-used-missing-alt', null, 'image/jpeg');
    insertCuratorSignalMedia('signal-unused-missing-alt', null, 'image/jpeg');

    TestCuratorOwner::query()->create(['name' => 'Signal Owner', 'image_id' => $usedMissingAltMediaId]);

    Event::fake([MediaMissingAltDetected::class]);

    $dispatched = DispatchMissingAltMediaSignalsAction::run(null, 1);

    expect($dispatched)->toBe(1);

    Event::assertDispatched(
        MediaMissingAltDetected::class,
        fn (MediaMissingAltDetected $event): bool => mediaHealthIntValue($event->media->getKey()) === $usedMissingAltMediaId
            && $event->usageCount === 1,
    );
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

/**
 * @param  Collection<int|string, CuratorMedia>  $records
 */
function mediaHealthRecord(Collection $records, int $mediaId): CuratorMedia
{
    $record = $records->get($mediaId);

    throw_unless($record instanceof CuratorMedia, RuntimeException::class, 'Expected media health query record.');

    return $record;
}

function mediaHealthIntAttribute(CuratorMedia $media, string $attribute): int
{
    return mediaHealthIntValue($media->getAttribute($attribute));
}

function mediaHealthIntValue(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

function insertCuratorSignalMedia(string $name, ?string $alt, string $type): int
{
    return DB::table('curator')->insertGetId([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => $name,
        'path' => 'media/' . $name . '.jpg',
        'width' => $type === 'image/jpeg' ? 800 : null,
        'height' => $type === 'image/jpeg' ? 600 : null,
        'size' => 10000,
        'type' => $type,
        'ext' => $type === 'image/jpeg' ? 'jpg' : 'pdf',
        'alt' => $alt,
        'title' => null,
        'description' => null,
        'caption' => null,
        'exif' => null,
        'curations' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function mediaHealthTableHarness(): Table
{
    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('makeFilamentTranslatableContentDriver')->andReturn(null);

    return Table::make($livewire);
}
