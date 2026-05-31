<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\DashboardReports\BuildMissingRightsMetadataQueryAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('missing rights metadata query reports curator rows without license or attribution metadata', function (): void {
    $missingExifMediaId = insertCuratorRightsMetadataMedia('missing-exif', null);
    $emptyExifMediaId = insertCuratorRightsMetadataMedia('empty-exif', '');
    $unrelatedExifMediaId = insertCuratorRightsMetadataMedia('unrelated-exif', [
        'camera' => 'Test body',
    ]);
    $licensedMediaId = insertCuratorRightsMetadataMedia('licensed', [
        'license' => 'CC BY 4.0',
    ]);
    $creditedMediaId = insertCuratorRightsMetadataMedia('credited', [
        'spatie_media_library' => [
            'custom_properties' => [
                'credit' => 'Capell Studio',
            ],
        ],
    ]);

    $records = BuildMissingRightsMetadataQueryAction::run()->get()->keyBy('id');

    expect($records->keys()->all())->toContain($missingExifMediaId, $emptyExifMediaId, $unrelatedExifMediaId)
        ->and($records->keys()->all())->not->toContain($licensedMediaId, $creditedMediaId);
});

test('missing rights metadata query can use custom metadata keys', function (): void {
    $sourceMediaId = insertCuratorRightsMetadataMedia('sourced', [
        'source_url' => 'https://example.test/image.jpg',
    ]);
    $unsourcedMediaId = insertCuratorRightsMetadataMedia('unsourced', [
        'camera' => 'Test body',
    ]);

    $records = BuildMissingRightsMetadataQueryAction::run(['source_url'])->get()->keyBy('id');

    expect($records->keys()->all())->toContain($unsourcedMediaId)
        ->and($records->keys()->all())->not->toContain($sourceMediaId);
});

test('missing rights metadata query is empty when curator table has not been installed', function (): void {
    Schema::dropIfExists('curator');

    expect(BuildMissingRightsMetadataQueryAction::run()->get())->toHaveCount(0);
});

/**
 * @param  array<array-key, mixed>|string|null  $exif
 */
function insertCuratorRightsMetadataMedia(string $name, array|string|null $exif): int
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
        'alt' => 'Alt text',
        'title' => null,
        'description' => null,
        'caption' => null,
        'exif' => is_array($exif) ? json_encode($exif, JSON_THROW_ON_ERROR) : $exif,
        'curations' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
