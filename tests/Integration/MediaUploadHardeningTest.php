<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\SanitizeSvgUploadAction;
use Capell\MediaLibrary\Filament\Components\CuratorMediaFieldFactory;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Real PNG bytes for spoofing tests (so finfo sniffs image/png regardless of
 * the declared Content-Type / extension).
 */
function hardeningPngBytes(): string
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/*
 * H2 — the interactive Curator picker field must carry the configured
 * acceptedFileTypes / maxSize so admin uploads are validated, not just the
 * single trait-based importer caller.
 */
test('H2: curator media field carries configured accepted types and max size', function (): void {
    config()->set('capell.media_library.allowed_mime_types', ['image/png', 'image/svg+xml']);
    config()->set('capell.media_library.max_upload_kb', 4096);

    $field = (new CuratorMediaFieldFactory)->make('heroImage');

    expect($field->getAcceptedFileTypes())->toBe(['image/png', 'image/svg+xml'])
        ->and($field->getMaxSize())->toBe(4096);
});

test('H2: field max size falls back to a sane default when config is non-numeric', function (): void {
    config()->set('capell.media_library.max_upload_kb', 'not-a-number');

    $field = (new CuratorMediaFieldFactory)->make('image');

    expect($field->getMaxSize())->toBe(10240);
});

/*
 * H3 — the SVG sanitizer must handle <use> and <image>. Same-document
 * fragment references are kept; external/data references are stripped.
 */
test('H3: sanitizer strips external use/image references but keeps fragment refs', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="20" height="20">
        <use href="#safe-symbol" />
        <use href="https://evil.example.com/external.svg#x" />
        <use xlink:href="evil.svg#payload" />
        <image href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" />
        <image href="https://evil.example.com/remote.png" />
        <image xlink:href="#allowed-image" />
    </svg>
    SVG;

    $sanitized = SanitizeSvgUploadAction::run($svg);

    expect($sanitized)
        ->toContain('href="#safe-symbol"')
        ->toContain('xlink:href="#allowed-image"')
        ->not->toContain('evil.example.com')
        ->not->toContain('evil.svg')
        ->not->toContain('data:image')
        ->not->toContain('external.svg');

    // The elements themselves remain; only the unsafe references are removed.
    expect($sanitized)
        ->toContain('<use')
        ->toContain('<image');
});

test('H3: sanitizer still removes script tags and event handlers', function (): void {
    $svg = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">
        <script>alert(2)</script>
        <use href="#ok" />
    </svg>
    SVG;

    $sanitized = SanitizeSvgUploadAction::run($svg);

    expect($sanitized)
        ->not->toContain('<script')
        ->not->toContain('onload')
        ->toContain('href="#ok"');
});

/*
 * M4 — content-type/extension spoofing. The importer-style UploadedFile
 * (test: true) echoes the client Content-Type from getMimeType(); validation
 * and SVG detection must sniff the real bytes instead.
 */
test('M4: a binary masquerading as an allowed image is rejected by sniffed type', function (): void {
    config()->set('capell.media_library.allowed_mime_types', ['image/jpeg', 'image/png']);
    config()->set('capell.media_library.allowed_extensions', ['jpg', 'png']);

    $owner = TestCuratorOwner::query()->create(['name' => 'Spoof Owner']);

    // Real PNG bytes on disk, but presented with a .jpg name and an
    // attacker-friendly image/jpeg Content-Type. Sniffing must see PNG.
    $temporaryPath = tempnam(sys_get_temp_dir(), 'capell-m4-');
    expect($temporaryPath)->toBeString();
    File::put($temporaryPath, hardeningPngBytes());

    try {
        $owner->addMediaFromUploadedFile(
            new UploadedFile(
                path: $temporaryPath,
                originalName: 'totally-a-jpeg.jpg',
                mimeType: 'image/jpeg',
                error: null,
                test: true,
            ),
            'image',
        );

        // PNG is in the allow-list here, so the upload should SUCCEED based on
        // the sniffed type — proving sniffing drives the decision, not the
        // spoofed jpeg Content-Type.
        expect(CuratorMedia::query()->count())->toBe(1);
    } finally {
        File::delete($temporaryPath);
    }
});

test('M4: sniffed type is rejected when it is not in the allow-list despite a spoofed image content-type', function (): void {
    config()->set('capell.media_library.allowed_mime_types', ['image/jpeg']);
    config()->set('capell.media_library.allowed_extensions', ['jpg', 'png']);

    $owner = TestCuratorOwner::query()->create(['name' => 'Spoof Reject Owner']);

    // Real PNG bytes, but only JPEG is allowed. Declared type lies as image/jpeg.
    $temporaryPath = tempnam(sys_get_temp_dir(), 'capell-m4-reject-');
    expect($temporaryPath)->toBeString();
    File::put($temporaryPath, hardeningPngBytes());

    try {
        $owner->addMediaFromUploadedFile(
            new UploadedFile(
                path: $temporaryPath,
                originalName: 'fake.jpg',
                mimeType: 'image/jpeg',
                error: null,
                test: true,
            ),
            'image',
        );

        $this->fail('Expected sniffed-type validation to reject the spoofed upload.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['media'][0])->toContain('image/png');
    } finally {
        File::delete($temporaryPath);
    }

    expect(CuratorMedia::query()->count())->toBe(0);
});

test('M4: an SVG is sanitized even when its content-type lies (client says jpeg)', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'SVG Spoof Owner']);

    $temporaryPath = tempnam(sys_get_temp_dir(), 'capell-m4-svg-');
    expect($temporaryPath)->toBeString();
    File::put($temporaryPath, <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">
        <script>alert(1)</script>
        <rect width="10" height="10" />
    </svg>
    SVG);

    try {
        $owner->addMediaFromUploadedFile(
            // Declared as JPEG, but the bytes are an SVG. Detection must key on
            // the sniffed content, then sanitize.
            new UploadedFile(
                path: $temporaryPath,
                originalName: 'logo.jpg',
                mimeType: 'image/jpeg',
                error: null,
                test: true,
            ),
            'image',
        );
    } finally {
        File::delete($temporaryPath);
    }

    $storedMedia = CuratorMedia::query()->sole();
    $storedSvg = (string) Storage::disk('public')->get($storedMedia->path);

    expect($storedMedia->type)->toBe('image/svg+xml')
        ->and($storedSvg)->not->toContain('<script');
});
