<?php

declare(strict_types=1);

use Capell\Admin\Support\CapellAdminManager;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthTotalsAction;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMissingAltMediaQueryAction;
use Capell\MediaLibrary\Actions\DispatchMissingAltMediaSignalsAction;
use Capell\MediaLibrary\Data\MediaHealthIssuesData;
use Capell\MediaLibrary\Enums\MediaHealthIssue;
use Capell\MediaLibrary\Enums\MediaLibraryPermission;
use Capell\MediaLibrary\Events\MediaMissingAltDetected;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\MediaLibrary\Filament\Pages\Tables\MediaHealthTable;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\MediaHealthTestUser;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
    expect(mediaHealthIssueValues($missingAltMedia))->toBe(['missing_alt']);
    expect(mediaHealthIntAttribute($unusedMedia, 'usage_count'))->toBe(0);
    expect(mediaHealthIssueValues($unusedMedia))->toBe(['unused']);
    expect(mediaHealthIssueValues($staleMedia))->toBe(['stale']);
});

test('media health query reports every issue a single record carries', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    $everyIssueMediaId = insertCuratorHealthMedia('every-issue', null, now()->subDays(31));
    $staleAndMissingAltMediaId = insertCuratorHealthMedia('stale-missing-alt', '', now()->subDays(31));
    $usedStaleMediaId = insertCuratorHealthMedia('used-stale', 'Stale alt text', now()->subDays(31));

    TestCuratorOwner::query()->create(['name' => 'Stale Missing Alt Owner', 'image_id' => $staleAndMissingAltMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Used Stale Owner', 'image_id' => $usedStaleMediaId]);

    $records = BuildMediaHealthQueryAction::run(null, false)->get()->keyBy('id');

    expect(mediaHealthIssueValues(mediaHealthRecord($records, $everyIssueMediaId)))
        ->toBe(['missing_alt', 'stale', 'unused'])
        ->and(mediaHealthIssueValues(mediaHealthRecord($records, $staleAndMissingAltMediaId)))
        ->toBe(['missing_alt', 'stale'])
        ->and(mediaHealthIssueValues(mediaHealthRecord($records, $usedStaleMediaId)))
        ->toBe(['stale']);
});

test('media health issue totals count every issue on overlapping records', function (): void {
    Cache::flush();
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    insertCuratorHealthMedia('totals-every-issue', null, now()->subDays(31));
    $usedMissingAltMediaId = insertCuratorHealthMedia('totals-missing-alt', null, now());
    insertCuratorHealthMedia('totals-unused', 'Unused alt text', now());

    TestCuratorOwner::query()->create(['name' => 'Totals Owner', 'image_id' => $usedMissingAltMediaId]);

    $totals = BuildMediaHealthTotalsAction::run(null, false);

    expect($totals->recordTotal)->toBe(3)
        ->and($totals->forIssue(MediaHealthIssue::MissingAlt))->toBe(2)
        ->and($totals->forIssue(MediaHealthIssue::Stale))->toBe(1)
        ->and($totals->forIssue(MediaHealthIssue::Unused))->toBe(2)
        ->and($totals->hasIssue(MediaHealthIssue::Stale))->toBeTrue();
});

test('media health issue column renders every issue badge for one record', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    $everyIssueMediaId = insertCuratorHealthMedia('column-every-issue', null, now()->subDays(31));

    $record = mediaHealthRecord(
        BuildMediaHealthQueryAction::run(null, false)->get()->keyBy('id'),
        $everyIssueMediaId,
    );

    expect(MediaHealthIssuesData::fromRecord($record)->labels())->toBe([
        __('capell-media-library::package.media_health.issues.missing_alt'),
        __('capell-media-library::package.media_health.issues.stale'),
        __('capell-media-library::package.media_health.issues.unused'),
    ]);
});

test('media health timestamp column is labelled as the field it renders', function (): void {
    $columns = MediaHealthTable::configure(mediaHealthTableHarness())->getColumns();

    expect($columns)->toHaveKey('updated_at')
        ->and($columns['updated_at']->getLabel())->toBe('Last updated')
        ->and($columns['updated_at']->getLabel())->not->toBe(__('capell-admin::table.last_used'));
});

test('media health page surfaces a primary next action and discloses the destructive one', function (): void {
    Cache::flush();
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    insertCuratorHealthMedia('action-missing-alt-first', null, now());
    insertCuratorHealthMedia('action-missing-alt-second', null, now());
    insertCuratorHealthMedia('action-stale', 'Stale alt text', now()->subDays(31));

    $nextActions = MediaHealthTable::nextActions();
    $primaryAction = $nextActions[0];
    $secondaryActionGroup = $nextActions[1];

    throw_unless($primaryAction instanceof Action, RuntimeException::class, 'Expected a primary media health action.');
    throw_unless($secondaryActionGroup instanceof ActionGroup, RuntimeException::class, 'Expected grouped media health actions.');

    $groupedActionNames = collect($secondaryActionGroup->getActions())
        ->map(static fn (Action|ActionGroup $action): string => $action instanceof Action ? (string) $action->getName() : '')
        ->values()
        ->all();

    expect($primaryAction->getName())->toBe('review_missing_alt')
        ->and($primaryAction->getLabel())->toBe('Review missing alt text (2)')
        ->and($groupedActionNames)->toBe(['review_stale', 'review_unused', 'delete_all_unused_media']);
});

test('each media health row exposes exactly one next action matching its primary issue', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    $missingAltId = insertCuratorHealthMedia('row-missing-alt', null, now());
    $staleId = insertCuratorHealthMedia('row-stale', 'Stale alt text', now()->subDays(31));
    $unusedId = insertCuratorHealthMedia('row-unused', 'Unused alt text', now());

    TestCuratorOwner::query()->create(['name' => 'Missing Alt Owner', 'image_id' => $missingAltId]);
    TestCuratorOwner::query()->create(['name' => 'Stale Owner', 'image_id' => $staleId]);

    $deleter = new MediaHealthTestUser(global: true, permissions: [MediaLibraryPermission::DeleteOrphanMedia->value]);
    auth()->setUser($deleter);

    $records = BuildMediaHealthQueryAction::run(null, false)->get()->keyBy('id');
    $actions = MediaHealthTable::rowActions();

    expect(mediaHealthVisibleRowActionName($actions, mediaHealthRecord($records, $missingAltId)))->toBe('edit_metadata')
        ->and(mediaHealthVisibleRowActionName($actions, mediaHealthRecord($records, $staleId)))->toBe('review_usage')
        ->and(mediaHealthVisibleRowActionName($actions, mediaHealthRecord($records, $unusedId)))->toBe('guarded_delete');
});

test('a fully healthy record (never returned by the health query itself) falls back to inspect', function (): void {
    // The base query only ever returns records with at least one issue, so a
    // healthy row never reaches the table in practice. This proves the row
    // action mapping is still correct and safe if that ever changes, using a
    // synthetic record instead of the query (which would filter it out).
    $healthyRecord = mediaHealthSyntheticRecord(missingAlt: false, stale: false, unused: false);

    expect(mediaHealthVisibleRowActionName(MediaHealthTable::rowActions(), $healthyRecord))->toBe('inspect');
});

test('an unused row falls back to inspect for an actor without destructive access', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $unusedId = insertCuratorHealthMedia('row-unused-readonly', 'Unused alt text', now());

    $viewer = new MediaHealthTestUser(global: true, permissions: [MediaLibraryPermission::ViewMediaHealth->value]);
    auth()->setUser($viewer);

    $records = BuildMediaHealthQueryAction::run(null, false)->get()->keyBy('id');
    $actions = MediaHealthTable::rowActions();

    expect(mediaHealthVisibleRowActionName($actions, mediaHealthRecord($records, $unusedId)))->toBe('inspect');
});

test('the edit metadata row action corrects alt text', function (): void {
    $mediaId = insertCuratorHealthMedia('row-edit-metadata', null, now());
    $record = CuratorMedia::query()->findOrFail($mediaId);

    MediaHealthTable::applyMetadataEdit($record, ['alt' => '  Corrected alt text  ']);

    expect(DB::table('curator')->where('id', $mediaId)->value('alt'))->toBe('Corrected alt text');
});

test('the guarded delete row action removes only its own record and requires destructive access', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $unusedId = insertCuratorHealthMedia('row-guarded-delete', 'Unused alt text', now());
    $record = CuratorMedia::query()->findOrFail($unusedId);

    $viewer = new MediaHealthTestUser(global: true, permissions: [MediaLibraryPermission::ViewMediaHealth->value]);
    auth()->setUser($viewer);

    expect(fn (): int => MediaHealthTable::applyGuardedDelete($record))->toThrow(AuthorizationException::class)
        ->and(DB::table('curator')->where('id', $unusedId)->exists())->toBeTrue();

    $deleter = new MediaHealthTestUser(global: true, permissions: [MediaLibraryPermission::DeleteOrphanMedia->value]);
    auth()->setUser($deleter);

    expect(MediaHealthTable::applyGuardedDelete($record))->toBe(1)
        ->and(DB::table('curator')->where('id', $unusedId)->exists())->toBeFalse();
});

test('review usage content is honest about missing owner-key configuration', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', []);

    $mediaId = insertCuratorHealthMedia('row-review-usage-unconfigured', 'Stale alt text', now()->subDays(200));
    $record = CuratorMedia::query()->findOrFail($mediaId);

    expect(MediaHealthTable::usageBreakdownContent($record)->toHtml())
        ->toContain('No owner foreign keys are configured');
});

test('review usage content reports the configured owner references it can prove', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $mediaId = insertCuratorHealthMedia('row-review-usage-configured', 'Stale alt text', now()->subDays(200));
    TestCuratorOwner::query()->create(['name' => 'Usage Owner', 'image_id' => $mediaId]);
    $record = CuratorMedia::query()->findOrFail($mediaId);

    expect(MediaHealthTable::usageBreakdownContent($record)->toHtml())
        ->toContain('test_curator_owners.image_id: 1');
});

test('inspect content honestly reports storage availability, including a disk that throws', function (): void {
    Storage::disk('public')->put('media/row-inspect-present.jpg', 'bytes');

    $presentRecord = mediaHealthSyntheticRecord(missingAlt: false, stale: false, unused: false, path: 'media/row-inspect-present.jpg');

    expect(MediaHealthTable::inspectContent($presentRecord)->toHtml())->toContain('Present on the configured disk.');

    $missingRecord = mediaHealthSyntheticRecord(missingAlt: false, stale: false, unused: false, path: 'media/row-inspect-missing.jpg');

    expect(MediaHealthTable::inspectContent($missingRecord)->toHtml())
        ->toContain('Not found on the configured disk, or the disk is unavailable.');

    Storage::shouldReceive('disk')->with('public')->andThrow(new RuntimeException('Disk unavailable.'));
    $throwingRecord = mediaHealthSyntheticRecord(missingAlt: false, stale: false, unused: false, path: 'media/row-inspect-present.jpg');

    expect(MediaHealthTable::inspectContent($throwingRecord)->toHtml())
        ->toContain('Not found on the configured disk, or the disk is unavailable.');
});

test('the empty state distinguishes unavailable unused detection from a genuine empty filter', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', []);

    expect(MediaHealthTable::emptyStateHeading('unused'))->toBe('Unused detection is unavailable')
        ->and(MediaHealthTable::emptyStateDescription('unused'))->toContain('No owner foreign keys are configured')
        ->and(MediaHealthTable::emptyStateHeading('stale'))->toBeNull()
        ->and(MediaHealthTable::emptyStateDescription('stale'))->toBe('No media currently carries the selected issue.')
        ->and(MediaHealthTable::emptyStateHeading(null))->toBeNull()
        ->and(MediaHealthTable::emptyStateDescription(null))->toBeNull();

    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    expect(MediaHealthTable::emptyStateHeading('unused'))->toBeNull()
        ->and(MediaHealthTable::emptyStateDescription('unused'))->toBe('No media currently carries the selected issue.');
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
        ->and(mediaHealthIssueValues(mediaHealthRecord($records, $unusedMediaId)))->toBe(['unused']);
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
        ->and(mediaHealthIssueValues(mediaHealthRecord($records, $staleMediaId)))->toBe(['stale']);
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
        ->and(mediaHealthIssueValues(mediaHealthRecord($cachedRecords, $firstMissingAltMediaId)))->toBe(['missing_alt']);
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

test('media health table issue filter returns every record carrying that issue', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    $healthyMediaId = insertCuratorHealthMedia('filter-healthy', 'Useful alt text', now());
    $missingAltMediaId = insertCuratorHealthMedia('filter-missing-alt', null, now());
    $staleUnusedMediaId = insertCuratorHealthMedia('filter-stale-unused', 'Stale alt text', now()->subDays(31));
    $unusedMediaId = insertCuratorHealthMedia('filter-unused', 'Unused alt text', now());
    $staleMissingAltMediaId = insertCuratorHealthMedia('filter-stale-missing-alt', null, now()->subDays(31));

    TestCuratorOwner::query()->create(['name' => 'Healthy Owner', 'image_id' => $healthyMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Missing Alt Owner', 'image_id' => $missingAltMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Stale Missing Alt Owner', 'image_id' => $staleMissingAltMediaId]);

    $filter = MediaHealthTable::configure(mediaHealthTableHarness())->getFilters()['media_health_issue'];
    $missingAltQuery = BuildMediaHealthQueryAction::run(null, false);
    $staleQuery = BuildMediaHealthQueryAction::run(null, false);
    $unusedQuery = BuildMediaHealthQueryAction::run(null, false);

    $filter->apply($missingAltQuery, ['value' => 'missing_alt']);
    $filter->apply($staleQuery, ['value' => 'stale']);
    $filter->apply($unusedQuery, ['value' => 'unused']);

    expect($missingAltQuery->pluck('id')->all())->toBe([$missingAltMediaId, $staleMissingAltMediaId])
        ->and($staleQuery->pluck('id')->all())->toBe([$staleUnusedMediaId, $staleMissingAltMediaId])
        ->and($unusedQuery->pluck('id')->all())->toBe([$staleUnusedMediaId, $unusedMediaId]);
});

test('the header totals reconcile with what each issue filter actually returns', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
    config()->set('capell.media_library.stale_after_days', 30);

    $healthyMediaId = insertCuratorHealthMedia('reconcile-healthy', 'Useful alt text', now());
    $missingAltMediaId = insertCuratorHealthMedia('reconcile-missing-alt', null, now());
    $staleUnusedMediaId = insertCuratorHealthMedia('reconcile-stale-unused', 'Stale alt text', now()->subDays(31));
    $unusedMediaId = insertCuratorHealthMedia('reconcile-unused', 'Unused alt text', now());
    $staleMissingAltMediaId = insertCuratorHealthMedia('reconcile-stale-missing-alt', null, now()->subDays(31));

    TestCuratorOwner::query()->create(['name' => 'Healthy Owner', 'image_id' => $healthyMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Missing Alt Owner', 'image_id' => $missingAltMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Stale Missing Alt Owner', 'image_id' => $staleMissingAltMediaId]);

    $totals = BuildMediaHealthTotalsAction::run(null, false);
    $filter = MediaHealthTable::configure(mediaHealthTableHarness())->getFilters()['media_health_issue'];

    foreach (MediaHealthIssue::all() as $issue) {
        $filteredQuery = BuildMediaHealthQueryAction::run(null, false);
        $filter->apply($filteredQuery, ['value' => $issue->value]);

        expect($totals->forIssue($issue))->toBe($filteredQuery->count());
    }

    expect($totals->recordTotal)->toBe(4);
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

/**
 * @return list<string>
 */
function mediaHealthIssueValues(CuratorMedia $media): array
{
    return MediaHealthIssuesData::fromRecord($media)->values();
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

/**
 * @param  array<int, Action>  $actions
 */
function mediaHealthVisibleRowActionName(array $actions, CuratorMedia $record): string
{
    $visibleNames = collect($actions)
        ->filter(fn (Action $action): bool => $action->record($record)->isVisible())
        ->map(fn (Action $action): string => (string) $action->getName())
        ->values()
        ->all();

    throw_unless(count($visibleNames) === 1, RuntimeException::class, sprintf(
        'Expected exactly one visible row action, got [%s].',
        implode(', ', $visibleNames),
    ));

    return $visibleNames[0];
}

/**
 * Builds an in-memory record carrying the same projected health-flag and
 * usage_count columns the real report query selects, without a database
 * round trip. Used to exercise row-action logic directly, including a
 * healthy combination the live report query never actually returns.
 */
function mediaHealthSyntheticRecord(
    bool $missingAlt,
    bool $stale,
    bool $unused,
    int $usageCount = 0,
    string $path = 'media/synthetic.jpg',
    string $disk = 'public',
): CuratorMedia {
    $record = new CuratorMedia;
    $record->forceFill([
        'id' => random_int(1_000_000, 9_999_999),
        'disk' => $disk,
        'path' => $path,
        'type' => 'image/jpeg',
        'alt' => $missingAlt ? null : 'Alt text',
        'has_missing_alt' => $missingAlt ? 1 : 0,
        'has_stale' => $stale ? 1 : 0,
        'has_unused' => $unused ? 1 : 0,
        'usage_count' => $usageCount,
    ]);
    $record->exists = true;

    return $record;
}
