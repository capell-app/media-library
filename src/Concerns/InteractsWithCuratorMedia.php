<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Concerns;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\MediaLibrary\Actions\SanitizeSvgUploadAction;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Capell media trait for Curator-backed owners. Implements HasMediaContract
 * using a single foreign key column per collection on the owner table.
 *
 * Convention: collection "image" maps to column "image_id"; "socialImage"
 * maps to "social_image_id"; etc. (Str::snake()). Consumer migrations must
 * add the FK columns.
 *
 * Single-FK means ONE media row per collection — no galleries. If you need
 * multi-item collections, stay on the default Spatie backend.
 *
 * @mixin Model
 */
trait InteractsWithCuratorMedia
{
    /** @var array<string, CuratorMedia|null> */
    private array $curatorMediaCache = [];

    public static function curatorMediaColumn(string $collection): string
    {
        return Str::snake($collection) . '_id';
    }

    /**
     * @return BelongsTo<CuratorMedia, $this>
     */
    public function curatorMediaRelation(string $collection): BelongsTo
    {
        return $this->belongsTo(CuratorMedia::class, static::curatorMediaColumn($collection));
    }

    /**
     * @return Collection<int, MediaContract>
     */
    public function getMedia(string $collection = 'default'): Collection
    {
        $media = $this->getFirstMedia($collection);

        /** @var Collection<int, MediaContract> $collectionResult */
        $collectionResult = $media === null
            ? new Collection
            : new Collection([$media]);

        return $collectionResult;
    }

    public function getFirstMedia(string $collection = 'default'): ?MediaContract
    {
        $column = static::curatorMediaColumn($collection);

        $mediaId = $this->getAttribute($column);

        if ($mediaId === null) {
            return null;
        }

        $cacheKey = $this->curatorMediaCacheKey($collection, $mediaId);

        if (array_key_exists($cacheKey, $this->curatorMediaCache)) {
            return $this->curatorMediaCache[$cacheKey];
        }

        /** @var CuratorMedia|null $media */
        $media = CuratorMedia::query()->find($mediaId);

        $this->curatorMediaCache[$cacheKey] = $media;

        return $this->curatorMediaCache[$cacheKey];
    }

    public function getFirstMediaUrl(string $collection = 'default', string $conversion = ''): string
    {
        $media = $this->getFirstMedia($collection);

        return $media?->getUrl($conversion) ?? '';
    }

    /**
     * @param  'public'|'private'|null  $visibility  Defaults to the configured
     *                                               visibility (public unless overridden).
     */
    public function addMediaFromUploadedFile(UploadedFile $file, string $collection = 'default', ?string $visibility = null): MediaContract
    {
        $this->validateMediaUpload($file);
        [$file, $temporarySanitizedPath] = $this->sanitizeSvgUpload($file);

        try {
            $visibility = $this->resolveMediaVisibility($visibility);
            $disk = $visibility === 'private' ? 'local' : 'public';

            $storedPath = $file->store('media', ['disk' => $disk, 'visibility' => $visibility]);

            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $baseName = pathinfo((string) $originalName, PATHINFO_FILENAME);

            /** @var CuratorMedia $media */
            $media = CuratorMedia::query()->create([
                'disk' => $disk,
                'directory' => 'media',
                'visibility' => $visibility,
                'name' => $baseName,
                'path' => $storedPath,
                'size' => $file->getSize(),
                'type' => $file->getMimeType(),
                'ext' => $extension,
                'alt' => null,
                'title' => null,
                'description' => null,
                'caption' => null,
                'exif' => null,
                'curations' => null,
            ]);

            $column = static::curatorMediaColumn($collection);
            $this->setAttribute($column, $media->getKey());
            $this->save();
            $this->curatorMediaCache[$this->curatorMediaCacheKey($collection, $media->getKey())] = $media;

            return $media;
        } finally {
            if ($temporarySanitizedPath !== null) {
                File::delete($temporarySanitizedPath);
            }
        }
    }

    public function clearMediaCollection(string $collection = 'default'): static
    {
        $column = static::curatorMediaColumn($collection);
        $this->setAttribute($column, null);
        $this->save();
        $this->forgetCuratorMediaCache($collection);

        return $this;
    }

    private function curatorMediaCacheKey(string $collection, mixed $mediaId): string
    {
        return $collection . ':' . (is_scalar($mediaId) ? (string) $mediaId : '');
    }

    private function forgetCuratorMediaCache(string $collection): void
    {
        $prefix = $collection . ':';

        foreach (array_keys($this->curatorMediaCache) as $cacheKey) {
            if (str_starts_with((string) $cacheKey, $prefix)) {
                unset($this->curatorMediaCache[$cacheKey]);
            }
        }
    }

    private function validateMediaUpload(UploadedFile $file): void
    {
        $mimeType = $file->getMimeType() ?? 'unknown';
        $extension = strtolower($file->getClientOriginalExtension());
        $sizeKilobytes = (int) ceil(max(0, (int) $file->getSize()) / 1024);
        $allowedMimeTypes = $this->allowedUploadMimeTypes();
        $allowedExtensions = $this->allowedUploadExtensions();
        $maxUploadKilobytes = $this->maxUploadKilobytes();

        // Sniff the real type from the stored bytes and validate it too, so a
        // binary that merely claims an allowed Content-Type (e.g. the WordPress
        // importer's test-mode UploadedFile, where getMimeType() echoes the
        // server-supplied header) cannot slip through. SVGs that finfo reports
        // as generic XML/text are normalised so a genuine SVG is still allowed.
        $sniffedMimeType = $this->resolveSniffedMimeType($file);

        if ($sniffedMimeType !== null && ! in_array($sniffedMimeType, $allowedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'media' => __('capell-media-library::package.validation.invalid_mime_type', [
                    'mime' => $sniffedMimeType,
                    'allowed' => $this->formatAllowedUploadValues($allowedMimeTypes),
                ]),
            ]);
        }

        if (! in_array($mimeType, $allowedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'media' => __('capell-media-library::package.validation.invalid_mime_type', [
                    'mime' => $mimeType,
                    'allowed' => $this->formatAllowedUploadValues($allowedMimeTypes),
                ]),
            ]);
        }

        if (! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'media' => __('capell-media-library::package.validation.invalid_extension', [
                    'extension' => $extension === '' ? __('capell-media-library::package.validation.no_extension') : '.' . $extension,
                    'allowed' => $this->formatAllowedUploadValues($allowedExtensions, '.'),
                ]),
            ]);
        }

        if ($sizeKilobytes > $maxUploadKilobytes) {
            throw ValidationException::withMessages([
                'media' => __('capell-media-library::package.validation.max_size', [
                    'actual' => $sizeKilobytes,
                    'max' => $maxUploadKilobytes,
                ]),
            ]);
        }
    }

    /**
     * @return array{0: UploadedFile, 1: string|null}
     */
    private function sanitizeSvgUpload(UploadedFile $file): array
    {
        if (! $this->isSvgUpload($file)) {
            return [$file, null];
        }

        try {
            $sanitized = SanitizeSvgUploadAction::run((string) File::get($file->getPathname()));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'media' => [__('capell-media-library::package.validation.invalid_svg')],
            ]);
        }

        $temporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capell-svg-upload-' . Str::random(32);

        File::put($temporaryPath, $sanitized);

        return [
            new UploadedFile(
                path: $temporaryPath,
                originalName: $file->getClientOriginalName(),
                mimeType: 'image/svg+xml',
                error: null,
                test: true,
            ),
            $temporaryPath,
        ];
    }

    private function isSvgUpload(UploadedFile $file): bool
    {
        $sniffed = $this->sniffMimeType($file);

        if ($sniffed !== null && str_contains($sniffed, 'svg')) {
            return true;
        }

        // finfo frequently classifies SVG as text/plain or text/xml; fall back
        // to a structural check on the stored bytes. Client extension/type are
        // deliberately NOT trusted here (see M4) and used only as a last hint
        // when the file content is genuinely XML.
        $contents = $this->readUploadedFileContents($file);

        if ($contents !== null) {
            $prologue = ltrim(substr($contents, 0, 1024));

            if (stripos($prologue, '<svg') !== false) {
                return true;
            }

            $declaredSvg = strtolower($file->getClientOriginalExtension()) === 'svg'
                || strtolower((string) $file->getMimeType()) === 'image/svg+xml';

            if ($declaredSvg && stripos($contents, '<svg') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The concrete sniffed MIME type to validate against the allow-list, or
     * null when the content is inconclusive (e.g. empty fixtures sniffed as
     * application/x-empty) — in which case validation falls back to the
     * declared type. Genuine SVGs are normalised to image/svg+xml because
     * finfo commonly reports SVG markup as text/xml or text/plain.
     */
    private function resolveSniffedMimeType(UploadedFile $file): ?string
    {
        if ($this->isSvgUpload($file)) {
            return 'image/svg+xml';
        }

        $sniffed = $this->sniffMimeType($file);

        if ($sniffed === null || in_array($sniffed, $this->inconclusiveSniffedTypes(), true)) {
            return null;
        }

        return $sniffed;
    }

    /**
     * Sniffed types that carry no security signal (empty or undetermined
     * content). These must not override the declared-type validation path.
     *
     * @return list<string>
     */
    private function inconclusiveSniffedTypes(): array
    {
        return [
            'application/x-empty',
            'inode/x-empty',
        ];
    }

    /**
     * Sniffs the real MIME type from the stored bytes rather than trusting the
     * client-supplied Content-Type / extension (which Symfony's UploadedFile
     * returns verbatim when constructed with test: true, as the WordPress
     * importer does). Returns null when the type cannot be determined.
     */
    private function sniffMimeType(UploadedFile $file): ?string
    {
        $path = $file->getPathname();

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        if (! function_exists('finfo_open')) {
            return null;
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($fileInfo === false) {
            return null;
        }

        try {
            $mimeType = finfo_file($fileInfo, $path);
        } finally {
            finfo_close($fileInfo);
        }

        return is_string($mimeType) && $mimeType !== '' ? strtolower($mimeType) : null;
    }

    private function readUploadedFileContents(UploadedFile $file): ?string
    {
        $path = $file->getPathname();

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * @param  'public'|'private'|null  $visibility
     * @return 'public'|'private'
     */
    private function resolveMediaVisibility(?string $visibility): string
    {
        if ($visibility === 'public' || $visibility === 'private') {
            return $visibility;
        }

        $configured = config('capell.media_library.default_visibility', 'public');

        return $configured === 'private' ? 'private' : 'public';
    }

    /**
     * @return list<string>
     */
    private function allowedUploadMimeTypes(): array
    {
        return $this->stringListConfig('capell.media_library.allowed_mime_types');
    }

    /**
     * @return list<string>
     */
    private function allowedUploadExtensions(): array
    {
        return array_map(
            strtolower(...),
            $this->stringListConfig('capell.media_library.allowed_extensions'),
        );
    }

    private function maxUploadKilobytes(): int
    {
        $maxUploadKilobytes = config('capell.media_library.max_upload_kb', 10240);

        return is_numeric($maxUploadKilobytes) ? max(1, (int) $maxUploadKilobytes) : 10240;
    }

    /**
     * @return list<string>
     */
    private function stringListConfig(string $key): array
    {
        $value = config($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? trim($entry) : '', $value),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * @param  list<string>  $values
     */
    private function formatAllowedUploadValues(array $values, string $prefix = ''): string
    {
        if ($values === []) {
            return __('capell-media-library::package.validation.none_configured');
        }

        return implode(', ', array_map(
            static fn (string $value): string => $prefix . ltrim($value, '.'),
            $values,
        ));
    }
}
