<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Concerns;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        /** @var CuratorMedia|null $media */
        $media = CuratorMedia::query()->find($mediaId);

        return $media;
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

        $visibility = $this->resolveMediaVisibility($visibility);
        $disk = $visibility === 'private' ? 'local' : 'public';

        $storedPath = $file->store('media', ['disk' => $disk, 'visibility' => $visibility]);

        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

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

        return $media;
    }

    public function clearMediaCollection(string $collection = 'default'): static
    {
        $column = static::curatorMediaColumn($collection);
        $this->setAttribute($column, null);
        $this->save();

        return $this;
    }

    private function validateMediaUpload(UploadedFile $file): void
    {
        $mimeType = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        $sizeKilobytes = (int) ceil(max(0, (int) $file->getSize()) / 1024);

        if (! in_array($mimeType, $this->allowedUploadMimeTypes(), true)) {
            throw ValidationException::withMessages([
                'media' => __('capell-media-library::package.validation.invalid_mime_type'),
            ]);
        }

        if (! in_array($extension, $this->allowedUploadExtensions(), true)) {
            throw ValidationException::withMessages([
                'media' => __('capell-media-library::package.validation.invalid_extension'),
            ]);
        }

        if ($sizeKilobytes > $this->maxUploadKilobytes()) {
            throw ValidationException::withMessages([
                'media' => __('capell-media-library::package.validation.max_size', [
                    'max' => $this->maxUploadKilobytes(),
                ]),
            ]);
        }
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
}
