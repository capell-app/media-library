<?php

declare(strict_types=1);

use Capell\Core\Contracts\Media\MediaContract;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
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

test('CuratorMedia suppresses unsafe responsive srcset metadata in anonymous output', function (): void {
    $media = publicRenderSafetyMedia([
        'responsive_images' => [
            'unsafe' => ['srcset' => '/admin/media/42/edit?signed-editor=1 1024w'],
        ],
    ]);

    expect($media->getSrcset())->toBe('');

    assertMediaLibraryPublicOutputIsSafe(mediaLibraryPublicOutput($media));
});

test('CuratorMedia omits admin and editor markers from non-admin public output', function (): void {
    $user = new class extends AuthenticatableUser
    {
        protected $guarded = [];
    };
    $user->setAttribute('id', 42);
    $user->setAttribute('email', 'visitor@example.test');

    $this->actingAs($user);

    $media = publicRenderSafetyMedia([
        'responsive_images' => [
            'hero' => ['srcset' => '/storage/media/public-640.jpg 640w, /storage/media/public-1024.jpg 1024w'],
        ],
    ]);

    assertMediaLibraryPublicOutputIsSafe(mediaLibraryPublicOutput($media));
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

/**
 * @param  array<string, mixed>  $overrides
 */
function publicRenderSafetyMedia(array $overrides = []): CuratorMedia
{
    return (new CuratorMedia)->forceFill([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => 'public-output',
        'path' => 'media/public-output.jpg',
        'width' => 1200,
        'height' => 800,
        'size' => 12345,
        'type' => 'application/pdf',
        'ext' => 'pdf',
        'alt' => 'Public output',
        'title' => null,
        'description' => null,
        'caption' => null,
        'exif' => null,
        'curations' => null,
        ...$overrides,
    ]);
}

function mediaLibraryPublicOutput(CuratorMedia $media): string
{
    return implode("\n", [
        $media->getUrl(),
        $media->getSrcset(),
    ]);
}

function assertMediaLibraryPublicOutputIsSafe(string $output): void
{
    $normalizedOutput = strtolower($output);

    expect($normalizedOutput)
        ->not->toContain('/admin')
        ->not->toContain('admin.')
        ->not->toContain('capell-app/')
        ->not->toContain('capell-frontend-authoring')
        ->not->toContain('capell-media-library')
        ->not->toContain('data-capell')
        ->not->toContain('field_path')
        ->not->toContain('filament')
        ->not->toContain('frontend-authoring')
        ->not->toContain('javascript:')
        ->not->toContain('livewire')
        ->not->toContain('model_id')
        ->not->toContain('permission')
        ->not->toContain('signed-editor')
        ->not->toContain('wire:');
}
