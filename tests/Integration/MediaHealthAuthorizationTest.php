<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Enums\MediaLibraryPermission;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\MediaLibrary\Support\MediaHealthAuthorization;
use Capell\MediaLibrary\Tests\Fixtures\MediaHealthTestUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

test('media health is global-only and keeps read and delete authority separate', function (): void {
    $globalViewer = new MediaHealthTestUser(global: true, permissions: [
        MediaLibraryPermission::ViewMediaHealth->value,
    ]);
    $globalDeleter = new MediaHealthTestUser(global: true, permissions: [
        MediaLibraryPermission::DeleteOrphanMedia->value,
    ]);
    $scopedOperator = new MediaHealthTestUser(permissions: MediaLibraryPermission::names());

    auth()->setUser($globalViewer);

    expect(MediaHealthPage::canAccess())->toBeTrue()
        ->and(MediaHealthAuthorization::canDeleteOrphanMedia($globalViewer))->toBeFalse()
        ->and(MediaHealthAuthorization::canView($globalDeleter))->toBeFalse()
        ->and(MediaHealthAuthorization::canDeleteOrphanMedia($globalDeleter))->toBeTrue()
        ->and(MediaHealthAuthorization::canView($scopedOperator))->toBeFalse()
        ->and(MediaHealthAuthorization::canDeleteOrphanMedia($scopedOperator))->toBeFalse();
});

test('orphan cleanup reauthorizes the actor before deleting global media', function (): void {
    $readOnlyGlobalActor = new MediaHealthTestUser(global: true, permissions: [
        MediaLibraryPermission::ViewMediaHealth->value,
    ]);
    $mediaId = DB::table('curator')->insertGetId([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => 'protected-orphan',
        'path' => 'media/protected-orphan.jpg',
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

    expect(fn (): int => DeleteOrphanMediaRecordsAction::run(
        $readOnlyGlobalActor,
        [['table' => 'test_curator_owners', 'column' => 'image_id']],
        mediaIds: [$mediaId],
    ))->toThrow(AuthorizationException::class)
        ->and(DB::table('curator')->where('id', $mediaId)->exists())->toBeTrue();
});
