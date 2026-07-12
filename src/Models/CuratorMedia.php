<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Models;

use Awcodes\Curator\Models\Media as BaseCuratorMedia;
use Capell\Core\Contracts\Media\MediaContract;
use Capell\MediaLibrary\Actions\SanitizeSvgUploadAction;
use Capell\MediaLibrary\Jobs\CalculateMediaChecksumJob;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Curator's Media model, extended to satisfy Capell's backend-agnostic
 * MediaContract. Subclasses Awcodes\Curator\Models\Media so Curator-native
 * features (picker, library, glide) continue to work untouched.
 *
 * Curator exposes Glide-backed thumbnail, medium, and large URLs rather than
 * Spatie conversion files. The contract methods map those existing URLs into
 * conversion/srcset semantics without changing Curator storage.
 */
final class CuratorMedia extends BaseCuratorMedia implements MediaContract
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    public function getUrl(string $conversion = ''): string
    {
        if ($this->isPrivateMedia()) {
            return $this->temporaryPrivateUrl();
        }

        $conversionUrl = match ($conversion) {
            'thumb', 'thumbnail' => $this->thumbnail_url,
            'medium' => $this->medium_url,
            'large' => $this->large_url,
            default => null,
        };

        if (is_string($conversionUrl) && $conversionUrl !== '') {
            return $conversionUrl;
        }

        // `url` is an Eloquent accessor on BaseCuratorMedia; reading
        // $this->url triggers it and returns a storage-resolved URL.
        return (string) $this->url;
    }

    public function getFullUrl(string $conversion = ''): string
    {
        return $this->getUrl($conversion);
    }

    /**
     * @param  array<int, string>  $conversions
     */
    public function getAvailableFullUrl(array $conversions): string
    {
        foreach ($conversions as $conversion) {
            if ($this->hasConversion($conversion)) {
                return $this->getUrl($conversion);
            }
        }

        return $this->getUrl();
    }

    public function getSrcset(): string
    {
        if ($this->isPrivateMedia()) {
            return '';
        }

        $responsiveImages = $this->metadataArray('responsive_images');

        foreach ($responsiveImages as $responsiveImage) {
            if (! is_array($responsiveImage)) {
                continue;
            }

            if (! is_string($responsiveImage['srcset'] ?? null)) {
                continue;
            }

            $srcset = trim($responsiveImage['srcset']);

            if ($srcset !== '' && $this->isPublicSafeSrcset($srcset)) {
                return $srcset;
            }
        }

        if (! str_starts_with($this->type, 'image/')) {
            return '';
        }

        $candidates = [
            ['conversion' => 'thumbnail', 'width' => 200],
            ['conversion' => 'medium', 'width' => 640],
            ['conversion' => 'large', 'width' => 1024],
        ];

        $srcset = collect($candidates)
            ->filter(fn (array $candidate): bool => $this->hasConversion($candidate['conversion']))
            ->map(fn (array $candidate): string => sprintf(
                '%s %dw',
                $this->getUrl($candidate['conversion']),
                $candidate['width'],
            ))
            ->values()
            ->all();

        return implode(', ', $srcset);
    }

    public function hasResponsiveImages(): bool
    {
        return $this->getSrcset() !== '';
    }

    public function hasConversion(string $conversion): bool
    {
        if (! str_starts_with($this->type, 'image/')) {
            return false;
        }

        if (in_array($conversion, ['thumb', 'thumbnail', 'medium', 'large'], true)) {
            return true;
        }

        $generatedConversions = $this->metadataArray('generated_conversions');

        return (bool) ($generatedConversions[$conversion] ?? false);
    }

    public function getName(): string
    {
        $name = $this->name ?? null;

        if ($name !== null && $name !== '') {
            return $name;
        }

        $prettyName = $this->pretty_name ?? null;

        return $prettyName ?? '';
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMimeType(): string
    {
        return $this->type;
    }

    public function getWidth(): int
    {
        return (int) ($this->width ?? 0);
    }

    public function getHeight(): int
    {
        return (int) ($this->height ?? 0);
    }

    public function getCustomProperty(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'alt' => $this->alt ?? $default,
            'title' => $this->title ?? $default,
            'description' => $this->description ?? $default,
            'caption' => $this->caption ?? $default,
            'width' => $this->width ?? $default,
            'height' => $this->height ?? $default,
            'focal' => $this->getFocalPoint(),
            default => $default,
        };
    }

    /**
     * @return array{x: int, y: int}
     */
    public function getFocalPoint(): array
    {
        $focalPoint = $this->capellCuration('capell_focal');

        return [
            'x' => $this->normalizePercentage(data_get($focalPoint, 'x', 50)),
            'y' => $this->normalizePercentage(data_get($focalPoint, 'y', 50)),
        ];
    }

    public function setFocalPoint(int $x, int $y): self
    {
        $this->upsertCapellCuration('capell_focal', [
            'x' => $this->normalizePercentage($x),
            'y' => $this->normalizePercentage($y),
        ]);

        return $this;
    }

    /**
     * @return array<string, array{focal: array{x: int, y: int}, updated_at: string|null}>
     */
    public function getCropPresets(): array
    {
        return collect($this->curationsArray())
            ->mapWithKeys(function (mixed $entry): array {
                $curation = $this->curationPayload($entry);
                $key = $curation['key'] ?? null;

                if (! is_string($key) || $key === '' || $key === 'capell_focal') {
                    return [];
                }

                $focal = is_array($curation['focal'] ?? null) ? $curation['focal'] : $curation;

                return [
                    $key => [
                        'focal' => [
                            'x' => $this->normalizePercentage(data_get($focal, 'x', data_get($this->getFocalPoint(), 'x'))),
                            'y' => $this->normalizePercentage(data_get($focal, 'y', data_get($this->getFocalPoint(), 'y'))),
                        ],
                        'updated_at' => is_string($curation['updated_at'] ?? null) ? $curation['updated_at'] : null,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @param  list<string>  $presetNames
     */
    public function setCropPresets(array $presetNames): self
    {
        $focalPoint = $this->getFocalPoint();
        $timestamp = now()->toISOString();

        $curations = collect($this->curationsArray())
            ->reject(function (mixed $entry): bool {
                $key = $this->curationPayload($entry)['key'] ?? null;

                return is_string($key) && $key !== 'capell_focal';
            })
            ->values()
            ->all();

        foreach (array_values(array_unique($presetNames)) as $presetName) {
            if (! is_string($presetName)) {
                continue;
            }

            if (trim($presetName) === '') {
                continue;
            }

            $curations[] = [
                'curation' => [
                    'key' => trim($presetName),
                    'focal' => $focalPoint,
                    'updated_at' => $timestamp,
                ],
            ];
        }

        $this->curations = $curations === [] ? null : json_encode($curations, JSON_THROW_ON_ERROR);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getCropPresetNames(): array
    {
        return array_keys($this->getCropPresets());
    }

    /**
     * @return array{x: int, y: int}
     */
    public function getFocalPointForConversion(string $conversion): array
    {
        $crop = $this->getCropPresets()[$conversion]['focal'] ?? null;

        if (is_array($crop)) {
            return [
                'x' => $this->normalizePercentage($crop['x'] ?? null),
                'y' => $this->normalizePercentage($crop['y'] ?? null),
            ];
        }

        return $this->getFocalPoint();
    }

    /**
     * Sanitize SVG blobs on the interactive upload path.
     *
     * Curator's modal Uploader stores the raw uploaded file to disk and then
     * creates this media row directly (bypassing the InteractsWithCuratorMedia
     * trait). To harden interactive admin uploads we re-read the stored bytes
     * after the row is created, and if they are an SVG (sniffed from content,
     * never from the client-supplied type/extension) we rewrite the stored
     * file with the sanitized markup. A failed sanitize removes the unsafe
     * blob so a dangerous SVG is never left served.
     */
    protected static function booted(): void
    {
        self::created(static function (self $media): void {
            $media->sanitizeStoredSvgInPlace();
            CalculateMediaChecksumJob::dispatch($media->getKey())->afterCommit();
        });
    }

    /**
     * Re-read this row's stored blob and, when it is genuinely an SVG (sniffed
     * from the bytes rather than trusting the declared type/extension),
     * overwrite it with sanitized markup. If sanitization fails the blob is
     * deleted so an unsafe SVG can never be served.
     */
    private function sanitizeStoredSvgInPlace(): void
    {
        $disk = $this->getAttribute('disk');
        $path = $this->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return;
        }

        try {
            $storageDisk = Storage::disk($disk);

            if (! $storageDisk->exists($path)) {
                return;
            }

            $contents = $storageDisk->get($path);

            if (! is_string($contents) || ! $this->bytesLookLikeSvg($contents)) {
                return;
            }

            $sanitized = SanitizeSvgUploadAction::run($contents);

            $storageDisk->put($path, $sanitized, $this->getAttribute('visibility') === 'private' ? 'private' : 'public');
        } catch (Throwable) {
            // The blob could not be safely sanitized; remove it rather than
            // leave a potentially dangerous SVG in place.
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable) {
                // Disk unavailable; nothing further we can safely do here.
            }
        }
    }

    private function bytesLookLikeSvg(string $contents): bool
    {
        $sniffed = $this->sniffMimeType($contents);

        if ($sniffed !== null && str_contains($sniffed, 'svg')) {
            return true;
        }

        // finfo classifies many SVGs as text/plain or text/xml; fall back to a
        // structural check on the leading bytes.
        $prologue = ltrim(substr($contents, 0, 1024));

        return stripos($prologue, '<svg') !== false
            || (stripos($prologue, '<?xml') !== false && stripos($contents, '<svg') !== false);
    }

    private function sniffMimeType(string $contents): ?string
    {
        if (! function_exists('finfo_open')) {
            return null;
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($fileInfo === false) {
            return null;
        }

        try {
            $mimeType = finfo_buffer($fileInfo, $contents);
        } finally {
            finfo_close($fileInfo);
        }

        return is_string($mimeType) && $mimeType !== '' ? strtolower($mimeType) : null;
    }

    private function isPrivateMedia(): bool
    {
        if ($this->getAttribute('visibility') === 'private') {
            return true;
        }

        $disk = $this->getAttribute('disk');
        $path = $this->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return false;
        }

        try {
            return Storage::disk($disk)->getVisibility($path) === 'private';
        } catch (Throwable) {
            return false;
        }
    }

    private function temporaryPrivateUrl(): string
    {
        $disk = $this->getAttribute('disk');
        $path = $this->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return '';
        }

        try {
            $storageDisk = Storage::disk($disk);

            if (! $storageDisk->providesTemporaryUrls()) {
                return '';
            }

            return $storageDisk->temporaryUrl($path, now()->addMinutes(5));
        } catch (Throwable) {
            return '';
        }
    }

    private function isPublicSafeSrcset(string $srcset): bool
    {
        return ! $this->containsUnsafePublicMarker($srcset);
    }

    private function containsUnsafePublicMarker(string $value): bool
    {
        $normalizedValue = strtolower($value);

        foreach ($this->unsafePublicOutputMarkers() as $marker) {
            if (str_contains($normalizedValue, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function unsafePublicOutputMarkers(): array
    {
        return [
            '/admin',
            'admin.',
            'capell-app/',
            'capell-frontend-authoring',
            'capell-media-library',
            'data-capell',
            'field_path',
            'filament',
            'frontend-authoring',
            'javascript:',
            'livewire',
            'model_id',
            'permission',
            'signed-editor',
            'wire:',
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metadataArray(string $attribute): array
    {
        $value = $this->getAttribute($attribute);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array<int, mixed>
     */
    private function curationsArray(): array
    {
        $curations = $this->metadataArray('curations');

        return array_is_list($curations) ? $curations : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function curationPayload(mixed $entry): array
    {
        if (! is_array($entry)) {
            return [];
        }

        if (isset($entry['curation']) && is_array($entry['curation'])) {
            return $entry['curation'];
        }

        return $entry;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function capellCuration(string $key): ?array
    {
        foreach ($this->curationsArray() as $entry) {
            $curation = $this->curationPayload($entry);

            if (($curation['key'] ?? null) === $key) {
                return $curation;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function upsertCapellCuration(string $key, array $payload): void
    {
        $curations = $this->curationsArray();
        $updated = false;

        foreach ($curations as $index => $entry) {
            $curation = $this->curationPayload($entry);

            if (($curation['key'] ?? null) !== $key) {
                continue;
            }

            $curations[$index] = ['curation' => ['key' => $key, ...$payload]];
            $updated = true;
        }

        if (! $updated) {
            $curations[] = ['curation' => ['key' => $key, ...$payload]];
        }

        $this->curations = json_encode($curations, JSON_THROW_ON_ERROR);
    }

    private function normalizePercentage(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 50;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
