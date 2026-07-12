<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Components;

use Awcodes\Curator\Components\Forms\CuratorPicker;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Illuminate\Support\Str;

/**
 * MediaFieldFactory implementation that returns a CuratorPicker Filament
 * field. Bound in the container by MediaLibraryServiceProvider so
 * Capell schemas that type-hint MediaFieldFactory render with Curator's
 * picker instead of Spatie's uploader.
 */
final class CuratorMediaFieldFactory implements MediaFieldFactory
{
    public function make(string $name): CuratorPicker
    {
        return CuratorPicker::make($this->resolveFieldName($name))
            ->acceptedFileTypes($this->allowedMimeTypes())
            ->maxSize($this->maxUploadKilobytes())
            ->dehydrated(fn (mixed $state): bool => filled($state));
    }

    /**
     * Accepted MIME types for interactive Curator uploads. Mirrors the
     * server-side allow-list enforced in InteractsWithCuratorMedia so the
     * picker rejects disallowed types client-side as well as on store.
     *
     * @return list<string>
     */
    private function allowedMimeTypes(): array
    {
        $configured = config('capell.media_library.allowed_mime_types', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? trim($entry) : '', $configured),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * Maximum upload size in kilobytes for interactive Curator uploads.
     * CuratorPicker::maxSize() (via Filament's FileUpload) expects kilobytes,
     * matching capell.media_library.max_upload_kb.
     */
    private function maxUploadKilobytes(): int
    {
        $maxUploadKilobytes = config('capell.media_library.max_upload_kb', 10240);

        return is_numeric($maxUploadKilobytes) ? max(1, (int) $maxUploadKilobytes) : 10240;
    }

    private function resolveFieldName(string $name): string
    {
        if (str_ends_with($name, '_id')) {
            return $name;
        }

        return Str::snake($name) . '_id';
    }
}
