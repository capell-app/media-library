<?php

declare(strict_types=1);

use Capell\Core\Contracts\Media\MediaContract;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Illuminate\Http\UploadedFile;

test('CuratorMedia contract methods return expected scalar types', function (): void {
    $mediaRow = CuratorMedia::query()->create([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => 'sample',
        'path' => 'media/sample.jpg',
        'size' => 12345,
        'type' => 'image/jpeg',
        'ext' => 'jpg',
        'alt' => null,
        'title' => null,
        'description' => null,
        'caption' => null,
        'exif' => null,
        'curations' => null,
    ]);

    expect($mediaRow->getName())->toBeString();
    expect($mediaRow->getPath())->toBeString()->not->toBeEmpty();
    expect($mediaRow->getMimeType())->toBeString()->not->toBeEmpty();
    expect($mediaRow->getUrl())->toBeString();
    expect($mediaRow->hasConversion('thumb'))->toBeBool();
    expect($mediaRow->hasResponsiveImages())->toBeBool();
});

test('CuratorMedia exposes stored responsive srcset metadata', function (): void {
    $media = (new CuratorMedia)->forceFill([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => 'responsive',
        'path' => 'media/responsive.jpg',
        'size' => 12345,
        'type' => 'image/jpeg',
        'ext' => 'jpg',
        'responsive_images' => [
            'hero' => ['srcset' => 'hero-640.jpg 640w, hero-1024.jpg 1024w'],
        ],
    ]);

    expect($media->getSrcset())->toBe('hero-640.jpg 640w, hero-1024.jpg 1024w')
        ->and($media->hasResponsiveImages())->toBeTrue()
        ->and($media->hasConversion('medium'))->toBeTrue();
});

test('getFirstMedia returns an object satisfying MediaContract for view components', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'Render Owner']);

    $owner->addMediaFromUploadedFile(
        UploadedFile::fake()->image('view-test.jpg'),
        'image',
    );

    $media = $owner->getFirstMedia('image');

    expect($media)->toBeInstanceOf(MediaContract::class);
});
