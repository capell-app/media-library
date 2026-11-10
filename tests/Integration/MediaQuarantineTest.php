<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Actions\PurgeQuarantinedMediaAction;
use Capell\MediaLibrary\Enums\MediaLibraryPermission;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\MediaHealthTestUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Cache::flush();
    Storage::fake('public');
    // A partial configured list: the discovered columns must still count.
    config()->set('capell.media_library.owner_foreign_keys', [
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ]);
});

function quarantineActor(): MediaHealthTestUser
{
    return new MediaHealthTestUser(global: true, permissions: [MediaLibraryPermission::DeleteOrphanMedia->value]);
}

function quarantineMedia(string $name): int
{
    Storage::disk('public')->put('media/' . $name . '.jpg', 'bytes-' . $name);

    return DB::table('curator')->insertGetId([
        'disk' => 'public', 'directory' => 'media', 'visibility' => 'public', 'name' => $name,
        'path' => 'media/' . $name . '.jpg', 'width' => 8, 'height' => 8, 'size' => 10, 'type' => 'image/jpeg',
        'ext' => 'jpg', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function quarantineRun(int $id): int
{
    return DeleteOrphanMediaRecordsAction::run(quarantineActor(), null, mediaIds: [$id]);
}

function quarantineIsQuarantined(int $id): bool
{
    return CuratorMedia::onlyTrashed()->whereKey($id)->exists();
}

test('media referenced only by a discovered property value column is not quarantined', function (): void {
    $id = quarantineMedia('property-ref');
    DB::table(TableName::resolve('page_property_values'))->insert([
        'site_id' => 1, 'page_id' => 1, 'property_definition_id' => 1, 'media_id' => $id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(quarantineRun($id))->toBe(0)
        ->and(quarantineIsQuarantined($id))->toBeFalse();
});

test('media targeted by a content graph edge is not quarantined', function (): void {
    $id = quarantineMedia('graph-ref');
    DB::table(TableName::resolve('content_graph_edges'))->insert([
        'source_type' => 'page', 'source_id' => 1, 'target_type' => CuratorMedia::class, 'target_id' => $id,
        'kind' => 'uses_media', 'strength' => 'weak', 'source_package' => 'test',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(quarantineRun($id))->toBe(0)
        ->and(quarantineIsQuarantined($id))->toBeFalse();
});

test('media used by widget settings JSON in layout elements is not quarantined', function (): void {
    $byId = quarantineMedia('json-id');
    $byPath = quarantineMedia('json-path');
    DB::table(TableName::resolve('layouts'))->insert([
        'name' => 'L', 'key' => 'l',
        'elements' => json_encode([['widget' => 'hero', 'settings' => ['backgroundImageId' => $byId, 'gallery' => ['images' => [['id' => 999999]]]]],
            ['widget' => 'img', 'settings' => ['src' => '/storage/media/json-path.jpg']]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(quarantineRun($byId))->toBe(0)
        ->and(quarantineRun($byPath))->toBe(0)
        ->and(quarantineIsQuarantined($byId))->toBeFalse()
        ->and(quarantineIsQuarantined($byPath))->toBeFalse();
});

test('media used in rich text is not quarantined', function (): void {
    $byPath = quarantineMedia('rich-path');
    $byAttribute = quarantineMedia('rich-attr');
    DB::table(TableName::resolve('translations'))->insert([
        'language_id' => 1, 'translatable_type' => 'page', 'translatable_id' => 1,
        'content' => '<p><img src="/storage/media/rich-path.jpg"><img data-media-id="' . $byAttribute . '" src="x"></p>',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(quarantineRun($byPath))->toBe(0)
        ->and(quarantineRun($byAttribute))->toBe(0)
        ->and(CuratorMedia::onlyTrashed()->count())->toBe(0);
});

test('unused media is quarantined and its blob stays intact', function (): void {
    $id = quarantineMedia('truly-unused');

    expect(quarantineRun($id))->toBe(1)
        ->and(quarantineIsQuarantined($id))->toBeTrue()
        ->and(CuratorMedia::query()->whereKey($id)->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists('media/truly-unused.jpg'))->toBeTrue();
});

test('purge removes the blob only after the retention period', function (): void {
    $fresh = quarantineMedia('purge-fresh');
    $old = quarantineMedia('purge-old');
    quarantineRun($fresh);
    quarantineRun($old);
    DB::table('curator')->where('id', $old)->update(['deleted_at' => now()->subDays(31)]);

    $result = PurgeQuarantinedMediaAction::run();

    expect($result->purgedIds)->toBe([$old])
        ->and($result->failedBlobDeletes)->toBe([])
        ->and(DB::table('curator')->where('id', $old)->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists('media/purge-old.jpg'))->toBeFalse()
        ->and(quarantineIsQuarantined($fresh))->toBeTrue()
        ->and(Storage::disk('public')->exists('media/purge-fresh.jpg'))->toBeTrue();
});

test('purge restores quarantined media that is referenced again', function (): void {
    $id = quarantineMedia('purge-rereferenced');
    quarantineRun($id);
    DB::table('curator')->where('id', $id)->update(['deleted_at' => now()->subDays(60)]);
    DB::table('test_curator_owners')->insert(['name' => 'Owner', 'thumbnail_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

    $result = PurgeQuarantinedMediaAction::run();

    expect($result->purgedIds)->toBe([])
        ->and($result->restoredIds)->toBe([$id])
        ->and(CuratorMedia::query()->whereKey($id)->exists())->toBeTrue()
        ->and(Storage::disk('public')->exists('media/purge-rereferenced.jpg'))->toBeTrue();
});

test('purge reports a failed blob delete instead of swallowing it', function (): void {
    $id = quarantineMedia('purge-failing');
    quarantineRun($id);
    DB::table('curator')->where('id', $id)->update(['deleted_at' => now()->subDays(60)]);
    $logSpy = Log::spy();
    Storage::shouldReceive('disk')->with('public')->andThrow(new RuntimeException('Disk unavailable.'));

    $result = PurgeQuarantinedMediaAction::run();

    expect($result->purgedIds)->toBe([$id])
        ->and($result->hasFailures())->toBeTrue()
        ->and($result->failedBlobDeletes[0]['error'])->toBe('Disk unavailable.')
        ->and($result->failedBlobDeletes[0]['media_id'])->toBe($id);
    $logSpy->shouldHaveReceived('warning')->once();
});

test('purge keeps a blob that another row still shares', function (): void {
    $id = quarantineMedia('shared-blob');
    $other = DB::table('curator')->insertGetId([
        'disk' => 'public', 'directory' => 'media', 'visibility' => 'public', 'name' => 'twin',
        'path' => 'media/shared-blob.jpg', 'type' => 'image/jpeg', 'ext' => 'jpg', 'created_at' => now(), 'updated_at' => now(),
    ]);
    quarantineRun($id);
    DB::table('curator')->where('id', $id)->update(['deleted_at' => now()->subDays(60)]);
    DB::table('test_curator_owners')->insert(['name' => 'Owner', 'image_id' => $other, 'created_at' => now(), 'updated_at' => now()]);

    $result = PurgeQuarantinedMediaAction::run();

    expect($result->purgedIds)->toBe([$id])
        ->and(Storage::disk('public')->exists('media/shared-blob.jpg'))->toBeTrue();
});
