<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\DashboardReports\BuildDuplicateMediaQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\BuildOrphanMediaQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('duplicate media query returns exact duplicate curator disk path rows', function (): void {
    $firstDuplicateId = insertCuratorGapMedia('first-duplicate', 'media/shared.jpg');
    $secondDuplicateId = insertCuratorGapMedia('second-duplicate', 'media/shared.jpg');
    $uniqueMediaId = insertCuratorGapMedia('unique', 'media/unique.jpg');

    $records = BuildDuplicateMediaQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->toContain($firstDuplicateId, $secondDuplicateId)
        ->and($records->keys()->all())->not->toContain($uniqueMediaId)
        ->and((int) $records->get($firstDuplicateId)->getAttribute('duplicate_count'))->toBe(2)
        ->and((int) $records->get($secondDuplicateId)->getAttribute('duplicate_count'))->toBe(2);
});

test('orphan media query returns unused media only when owner keys are known', function (): void {
    $usedMediaId = insertCuratorGapMedia('used', 'media/used.jpg');
    $orphanMediaId = insertCuratorGapMedia('orphan', 'media/orphan.jpg');

    TestCuratorOwner::query()->create(['name' => 'Owner', 'image_id' => $usedMediaId]);

    $records = BuildOrphanMediaQueryAction::run([
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
        ['table' => 'test_curator_owners;drop', 'column' => 'image_id'],
        ['table' => 'missing_table', 'column' => 'image_id'],
        ['table' => 'test_curator_owners', 'column' => 'missing_column'],
    ])->get()->keyBy('id');

    expect($records->keys()->all())->toContain($orphanMediaId)
        ->and($records->keys()->all())->not->toContain($usedMediaId)
        ->and((int) $records->get($orphanMediaId)->getAttribute('usage_count'))->toBe(0);
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

    $deleted = DeleteOrphanMediaRecordsAction::run([
        ['table' => 'test_curator_owners', 'column' => 'image_id'],
    ], limit: 1);

    expect($deleted)->toBe(1)
        ->and(DB::table('curator')->where('id', $usedMediaId)->exists())->toBeTrue()
        ->and(DB::table('curator')->where('id', $firstOrphanMediaId)->exists())->toBeBool()
        ->and(DB::table('curator')->where('id', $secondOrphanMediaId)->exists())->toBeBool()
        ->and(DB::table('curator')->whereIn('id', [$firstOrphanMediaId, $secondOrphanMediaId])->count())->toBe(1);
});

test('orphan media cleanup does nothing without known owner keys', function (): void {
    insertCuratorGapMedia('orphan-without-owners', 'media/orphan-without-owners.jpg');

    expect(DeleteOrphanMediaRecordsAction::run())->toBe(0)
        ->and(DB::table('curator')->count())->toBe(1);
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
