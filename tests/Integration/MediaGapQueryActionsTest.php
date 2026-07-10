<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\DashboardReports\BuildDuplicateMediaQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaUsageDrilldownAction;
use Capell\MediaLibrary\Actions\DashboardReports\BuildOrphanMediaQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Data\MediaUsageReferenceData;
use Capell\MediaLibrary\Enums\MediaLibraryPermission;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\MediaHealthTestUser;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

test('duplicate media query returns byte-identical curator rows across different paths', function (): void {
    Storage::disk('public')->put('media/first-duplicate.jpg', 'same-image-bytes');
    Storage::disk('public')->put('media/second-duplicate.jpg', 'same-image-bytes');
    Storage::disk('public')->put('media/unique.jpg', 'different-image-bytes');

    $firstDuplicateId = insertCuratorGapMedia('first-duplicate', 'media/first-duplicate.jpg');
    $secondDuplicateId = insertCuratorGapMedia('second-duplicate', 'media/second-duplicate.jpg');
    $uniqueMediaId = insertCuratorGapMedia('unique', 'media/unique.jpg');
    $missingFileMediaId = insertCuratorGapMedia('missing-file', 'media/missing-file.jpg');

    $records = BuildDuplicateMediaQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->toContain($firstDuplicateId, $secondDuplicateId)
        ->and($records->keys()->all())->not->toContain($uniqueMediaId, $missingFileMediaId)
        ->and(mediaGapIntAttribute(mediaGapRecord($records, $firstDuplicateId), 'duplicate_count'))->toBe(2)
        ->and(mediaGapIntAttribute(mediaGapRecord($records, $secondDuplicateId), 'duplicate_count'))->toBe(2)
        ->and(mediaGapStringAttribute(mediaGapRecord($records, $firstDuplicateId), 'duplicate_hash'))->toBe(hash('sha256', 'same-image-bytes'))
        ->and(mediaGapStringAttribute(mediaGapRecord($records, $secondDuplicateId), 'duplicate_hash'))->toBe(hash('sha256', 'same-image-bytes'));
});

test('orphan media query returns unused media from configured owner foreign keys', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
        ['table' => 'test_curator_owners', 'column' => 'thumbnail_id'],
        ['table' => 'test_curator_owners;drop', 'column' => 'image_id'],
        ['table' => 'missing_table', 'column' => 'image_id'],
        ['table' => 'test_curator_owners', 'column' => 'missing_column'],
    ]);

    $usedMediaId = insertCuratorGapMedia('used', 'media/used.jpg');
    $thumbnailMediaId = insertCuratorGapMedia('thumbnail-used', 'media/thumbnail-used.jpg');
    $orphanMediaId = insertCuratorGapMedia('orphan', 'media/orphan.jpg');

    TestCuratorOwner::query()->create(['name' => 'Owner', 'image_id' => $usedMediaId]);
    TestCuratorOwner::query()->create(['name' => 'Thumbnail Owner', 'thumbnail_id' => $thumbnailMediaId]);

    $records = BuildOrphanMediaQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->toContain($orphanMediaId)
        ->and($records->keys()->all())->not->toContain($usedMediaId, $thumbnailMediaId)
        ->and(mediaGapIntAttribute(mediaGapRecord($records, $orphanMediaId), 'usage_count'))->toBe(0);
});

test('orphan media query discovers conventional owner foreign keys by default', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', []);

    $usedMediaId = insertCuratorGapMedia('discovered-used', 'media/discovered-used.jpg');
    $orphanMediaId = insertCuratorGapMedia('discovered-orphan', 'media/discovered-orphan.jpg');

    TestCuratorOwner::query()->create(['name' => 'Discovered Owner', 'image_id' => $usedMediaId]);

    $records = BuildOrphanMediaQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->toContain($orphanMediaId)
        ->and($records->keys()->all())->not->toContain($usedMediaId)
        ->and(mediaGapIntAttribute(mediaGapRecord($records, $orphanMediaId), 'usage_count'))->toBe(0);
});

test('media usage drilldown returns configured owner records for a media item', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
        ['table' => 'test_curator_owners', 'column' => 'thumbnail_id'],
        ['table' => 'test_curator_owners;drop', 'column' => 'image_id'],
        ['table' => 'test_curator_owners', 'column' => 'missing_column'],
    ]);

    $mediaId = insertCuratorGapMedia('drilldown-used', 'media/drilldown-used.jpg');
    $otherMediaId = insertCuratorGapMedia('drilldown-other', 'media/drilldown-other.jpg');
    $imageOwner = TestCuratorOwner::query()->create(['name' => 'Hero Owner', 'image_id' => $mediaId]);
    $thumbnailOwner = TestCuratorOwner::query()->create(['name' => 'Thumbnail Owner', 'thumbnail_id' => $mediaId]);
    TestCuratorOwner::query()->create(['name' => 'Other Owner', 'image_id' => $otherMediaId]);

    $references = BuildMediaUsageDrilldownAction::run($mediaId);
    $limitedReferences = BuildMediaUsageDrilldownAction::run($mediaId, limit: 1);

    expect($references)->toHaveCount(2)
        ->and($references[0])->toBeInstanceOf(MediaUsageReferenceData::class)
        ->and(mediaUsageReferenceRows($references))->toBe([
            ['test_curator_owners', 'image_id', mediaGapModelKey($imageOwner), 'Hero Owner'],
            ['test_curator_owners', 'thumbnail_id', mediaGapModelKey($thumbnailOwner), 'Thumbnail Owner'],
        ])
        ->and(mediaUsageReferenceRows($limitedReferences))->toBe([
            ['test_curator_owners', 'image_id', mediaGapModelKey($imageOwner), 'Hero Owner'],
        ]);
});

test('orphan media query rebuilds a query from cached report rows', function (): void {
    Cache::flush();
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $firstOrphanMediaId = insertCuratorGapMedia('cached-first-orphan', 'media/cached-first-orphan.jpg');

    $firstRecords = BuildOrphanMediaQueryAction::run()->get()->keyBy('id');

    $secondOrphanMediaId = insertCuratorGapMedia('cached-second-orphan', 'media/cached-second-orphan.jpg');

    $cachedRecords = BuildOrphanMediaQueryAction::run()->get()->keyBy('id');
    $liveRecords = BuildOrphanMediaQueryAction::run(null, false)->get()->keyBy('id');

    expect($firstRecords->keys()->all())->toBe([$firstOrphanMediaId])
        ->and($cachedRecords->keys()->all())->toBe([$firstOrphanMediaId])
        ->and($liveRecords->keys()->all())->toContain($firstOrphanMediaId, $secondOrphanMediaId)
        ->and(mediaGapIntAttribute(mediaGapRecord($cachedRecords, $firstOrphanMediaId), 'usage_count'))->toBe(0);
});

test('orphan media query skips discovery when it is disabled', function (): void {
    config()->set('capell.media_library.owner_foreign_keys', []);
    config()->set('capell.media_library.auto_discover_owner_foreign_keys', false);

    insertCuratorGapMedia('disabled-discovery-orphan', 'media/disabled-discovery-orphan.jpg');

    expect(BuildOrphanMediaQueryAction::run()->get())->toHaveCount(0);
});

test('media gap queries are empty when curator table has not been installed', function (): void {
    Schema::dropIfExists('curator');

    expect(BuildDuplicateMediaQueryAction::run()->get())->toHaveCount(0)
        ->and(BuildOrphanMediaQueryAction::run([
            ['table' => 'test_curator_owners', 'column' => 'image_id'],
        ])->get())->toHaveCount(0);
});

test('orphan media cleanup deletes only unused curator records', function (): void {
    $usedMediaId = insertCuratorGapMedia('used-cleanup', 'media/used-cleanup.jpg');
    $firstOrphanMediaId = insertCuratorGapMedia('orphan-cleanup-first', 'media/orphan-cleanup-first.jpg');
    $secondOrphanMediaId = insertCuratorGapMedia('orphan-cleanup-second', 'media/orphan-cleanup-second.jpg');

    TestCuratorOwner::query()->create(['name' => 'Owner', 'image_id' => $usedMediaId]);

    $deleted = DeleteOrphanMediaRecordsAction::run(mediaGapDeleteActor(), [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ], limit: 1);

    expect($deleted)->toBe(1)
        ->and(DB::table('curator')->where('id', $usedMediaId)->exists())->toBeTrue()
        ->and(DB::table('curator')->where('id', $firstOrphanMediaId)->exists())->toBeBool()
        ->and(DB::table('curator')->where('id', $secondOrphanMediaId)->exists())->toBeBool()
        ->and(DB::table('curator')->whereIn('id', [$firstOrphanMediaId, $secondOrphanMediaId])->count())->toBe(1);
});

test('orphan media cleanup bypasses cached report rows before deleting', function (): void {
    Cache::flush();
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    $mediaId = insertCuratorGapMedia('cached-live-revalidation', 'media/cached-live-revalidation.jpg');

    expect(BuildOrphanMediaQueryAction::run()->pluck('id')->all())->toBe([$mediaId]);

    TestCuratorOwner::query()->create(['name' => 'Late Owner', 'image_id' => $mediaId]);

    $deleted = DeleteOrphanMediaRecordsAction::run(mediaGapDeleteActor(), [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    expect($deleted)->toBe(0)
        ->and(DB::table('curator')->where('id', $mediaId)->exists())->toBeTrue();
});

test('orphan media cleanup accepts selected media ids but deletes only unused records', function (): void {
    Storage::disk('public')->put('media/selected-used.jpg', 'image-bytes');
    Storage::disk('public')->put('media/selected-orphan.jpg', 'image-bytes');

    $usedMediaId = insertCuratorGapMedia('selected-used', 'media/selected-used.jpg');
    $orphanMediaId = insertCuratorGapMedia('selected-orphan', 'media/selected-orphan.jpg');
    $unselectedOrphanMediaId = insertCuratorGapMedia('unselected-orphan', 'media/unselected-orphan.jpg');

    TestCuratorOwner::query()->create(['name' => 'Selected Owner', 'image_id' => $usedMediaId]);

    $deleted = DeleteOrphanMediaRecordsAction::run(mediaGapDeleteActor(), [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ], mediaIds: [$usedMediaId, $orphanMediaId]);

    expect($deleted)->toBe(1)
        ->and(DB::table('curator')->where('id', $usedMediaId)->exists())->toBeTrue()
        ->and(DB::table('curator')->where('id', $orphanMediaId)->exists())->toBeFalse()
        ->and(DB::table('curator')->where('id', $unselectedOrphanMediaId)->exists())->toBeTrue()
        ->and(Storage::disk('public')->exists('media/selected-used.jpg'))->toBeTrue()
        ->and(Storage::disk('public')->exists('media/selected-orphan.jpg'))->toBeFalse();
});

test('orphan media cleanup does nothing without known owner keys', function (): void {
    insertCuratorGapMedia('orphan-without-owners', 'media/orphan-without-owners.jpg');

    expect(DeleteOrphanMediaRecordsAction::run(mediaGapDeleteActor(), []))->toBe(0)
        ->and(DB::table('curator')->count())->toBe(1);
});

test('orphan media cleanup deletes the underlying storage file', function (): void {
    Storage::disk('public')->put('media/orphan-file.jpg', 'image-bytes');

    $orphanMediaId = insertCuratorGapMedia('orphan-with-file', 'media/orphan-file.jpg');

    expect(Storage::disk('public')->exists('media/orphan-file.jpg'))->toBeTrue();

    $deleted = DeleteOrphanMediaRecordsAction::run(mediaGapDeleteActor(), [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    expect($deleted)->toBe(1)
        ->and(DB::table('curator')->where('id', $orphanMediaId)->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists('media/orphan-file.jpg'))->toBeFalse();
});

test('orphan media cleanup keeps files still referenced by another curator row', function (): void {
    Storage::disk('public')->put('media/shared-file.jpg', 'image-bytes');

    $orphanMediaId = insertCuratorGapMedia('orphan-shared', 'media/shared-file.jpg');
    $usedMediaId = insertCuratorGapMedia('used-shared', 'media/shared-file.jpg');

    TestCuratorOwner::query()->create(['name' => 'Sharing Owner', 'image_id' => $usedMediaId]);

    $deleted = DeleteOrphanMediaRecordsAction::run(mediaGapDeleteActor(), [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);

    expect($deleted)->toBe(1)
        ->and(DB::table('curator')->where('id', $orphanMediaId)->exists())->toBeFalse()
        ->and(DB::table('curator')->where('id', $usedMediaId)->exists())->toBeTrue()
        ->and(Storage::disk('public')->exists('media/shared-file.jpg'))->toBeTrue();
});

function insertCuratorGapMedia(string $name, string $path): int
{
    return DB::table('curator')->insertGetId([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => $name,
        'path' => $path,
        'width' => 800,
        'height' => 600,
        'size' => 10000,
        'type' => 'image/jpeg',
        'ext' => 'jpg',
        'alt' => 'Alt text',
        'title' => null,
        'description' => null,
        'caption' => null,
        'exif' => null,
        'curations' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function mediaGapDeleteActor(): MediaHealthTestUser
{
    return new MediaHealthTestUser(global: true, permissions: [
        MediaLibraryPermission::DeleteOrphanMedia->value,
    ]);
}

/**
 * @param  Collection<int|string, CuratorMedia>  $records
 */
function mediaGapRecord(Collection $records, int $mediaId): CuratorMedia
{
    $record = $records->get($mediaId);

    throw_unless($record instanceof CuratorMedia, RuntimeException::class, 'Expected media gap query record.');

    return $record;
}

function mediaGapIntAttribute(CuratorMedia $media, string $attribute): int
{
    $value = $media->getAttribute($attribute);

    return is_numeric($value) ? (int) $value : 0;
}

function mediaGapStringAttribute(CuratorMedia $media, string $attribute): string
{
    $value = $media->getAttribute($attribute);

    return is_string($value) || is_int($value) || is_float($value)
        ? (string) $value
        : '';
}

function mediaGapModelKey(Model $model): string
{
    $key = $model->getKey();

    return is_string($key) || is_int($key) || is_float($key)
        ? (string) $key
        : '';
}

/**
 * @param  list<MediaUsageReferenceData>  $references
 * @return list<array{string, string, string, string|null}>
 */
function mediaUsageReferenceRows(array $references): array
{
    return array_map(
        static fn (MediaUsageReferenceData $reference): array => [
            $reference->table,
            $reference->column,
            $reference->recordId,
            $reference->label,
        ],
        $references,
    );
}
