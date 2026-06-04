<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\MigrateSpatieMediaToCuratorAction;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

test('upload defaults to public visibility, preserving historic behaviour', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'Public Owner']);

    $media = mediaVisibilityCuratorMedia($owner->addMediaFromUploadedFile(
        UploadedFile::fake()->image('public.jpg'),
        'image',
    ));

    expect($media->getAttribute('visibility'))->toBe('public')
        ->and($media->getAttribute('disk'))->toBe('public');
});

test('upload honours the configured default visibility', function (): void {
    Storage::fake('local');
    Config::set('capell.media_library.default_visibility', 'private');

    $owner = TestCuratorOwner::query()->create(['name' => 'Config Owner']);

    $media = mediaVisibilityCuratorMedia($owner->addMediaFromUploadedFile(
        UploadedFile::fake()->image('config-private.jpg'),
        'image',
    ));

    expect($media->getAttribute('visibility'))->toBe('private')
        ->and($media->getAttribute('disk'))->toBe('local');
});

test('upload accepts an explicit private visibility without breaking public access by default', function (): void {
    Storage::fake('local');

    $owner = TestCuratorOwner::query()->create(['name' => 'Explicit Owner']);

    $privateMedia = mediaVisibilityCuratorMedia($owner->addMediaFromUploadedFile(
        UploadedFile::fake()->image('explicit-private.jpg'),
        'image',
        'private',
    ));

    expect($privateMedia->getAttribute('visibility'))->toBe('private')
        ->and($privateMedia->getAttribute('disk'))->toBe('local');

    $publicOwner = TestCuratorOwner::query()->create(['name' => 'Still Public Owner']);

    $publicMedia = mediaVisibilityCuratorMedia($publicOwner->addMediaFromUploadedFile(
        UploadedFile::fake()->image('still-public.jpg'),
        'image',
    ));

    expect($publicMedia->getAttribute('visibility'))->toBe('public');
});

test('migration preserves a private source disk visibility instead of forcing public', function (): void {
    Config::set('filesystems.disks.private_source', [
        'driver' => 'local',
        'root' => storage_path('app/private-source'),
        'visibility' => 'private',
    ]);

    $action = new MigrateSpatieMediaToCuratorAction;

    $reflection = new ReflectionMethod($action, 'resolveSourceDiskVisibility');

    expect($reflection->invoke($action, 'private_source'))->toBe('private')
        ->and($reflection->invoke($action, 'public'))->toBe('public');
});

function mediaVisibilityCuratorMedia(mixed $media): CuratorMedia
{
    throw_unless($media instanceof CuratorMedia, RuntimeException::class, 'Expected uploaded media to be stored as a Curator media model.');

    return $media;
}
