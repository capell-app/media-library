<?php

declare(strict_types=1);

use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\SvgContentDetector;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function longPrologueSvg(): string
{
    return '<!--' . str_repeat(' padding', 400) . " -->\n"
        . '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script><rect width="10" height="10"/></svg>';
}

function createStoredMediaRow(string $path, string $contents, string $type, string $ext): CuratorMedia
{
    Storage::disk('public')->put($path, $contents);

    /** @var CuratorMedia $media */
    $media = CuratorMedia::query()->create([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => 'upload',
        'path' => $path,
        'size' => strlen($contents),
        'type' => $type,
        'ext' => $ext,
    ]);

    return $media;
}

it('sanitises an interactively stored SVG whose element sits beyond the first kilobyte', function (): void {
    Storage::fake('public');

    createStoredMediaRow('media/long.svg', longPrologueSvg(), 'image/svg+xml', 'svg');

    expect(Storage::disk('public')->get('media/long.svg'))->not->toContain('<script');
});

it('sanitises SVG markup stored under a declared raster type', function (): void {
    Storage::fake('public');

    createStoredMediaRow('media/spoof.png', longPrologueSvg(), 'image/png', 'png');

    expect(Storage::disk('public')->get('media/spoof.png'))->not->toContain('<script');
});

it('removes a declared SVG that cannot be sanitised instead of serving it', function (): void {
    Storage::fake('public');

    createStoredMediaRow('media/broken.svg', '<html><body onload="alert(1)">not an svg</body></html>', 'image/svg+xml', 'svg');

    expect(Storage::disk('public')->exists('media/broken.svg'))->toBeFalse();
});

it('never stores an unsanitised trait upload with a long prologue', function (): void {
    Storage::fake('public');
    $owner = TestCuratorOwner::query()->create(['name' => 'Long prologue owner']);

    $temporaryPath = tempnam(sys_get_temp_dir(), 'capell-svg-prologue-');
    File::put($temporaryPath, longPrologueSvg());

    try {
        expect(fn (): mixed => $owner->addMediaFromUploadedFile(
            new UploadedFile(path: $temporaryPath, originalName: 'logo.svg', mimeType: 'image/svg+xml', error: null, test: true),
            'image',
        ))->toThrow(ValidationException::class);
    } finally {
        File::delete($temporaryPath);
    }

    expect(CuratorMedia::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('treats any SVG signal as an SVG', function (): void {
    expect(SvgContentDetector::isSvg(longPrologueSvg()))->toBeTrue()
        ->and(SvgContentDetector::isSvg('plain', 'image/svg+xml'))->toBeTrue()
        ->and(SvgContentDetector::isSvg('plain', null, '.SVG'))->toBeTrue()
        ->and(SvgContentDetector::isSvg('plain text', 'text/plain', 'txt'))->toBeFalse();
});
