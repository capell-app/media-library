<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Components;

use Awcodes\Curator\Components\Forms\Uploader;
use Capell\MediaLibrary\Support\VisibilityAwareTemporaryUpload;
use Closure;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use ReflectionProperty;

/** Scopes visibility handling to uploaders constructed by the package. */
final class CuratorUploader
{
    /**
     * @template T
     *
     * @param  Closure(): T  $during
     * @return T
     */
    public static function during(Closure $during): mixed
    {
        return Uploader::configureUsing(
            static function (Uploader $uploader): void {
                // Curator exposes the setter but no getter for its save callback.
                // Run after Filament configuration so host callbacks remain intact.
                $save = new ReflectionProperty($uploader, 'saveUploadedFileUsing')->getValue($uploader);
                if (! $save instanceof Closure) {
                    return;
                }

                $uploader->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): mixed => $uploader->evaluate($save, [
                    'file' => new VisibilityAwareTemporaryUpload($file, $uploader->getVisibility()),
                ]));
            },
            during: $during,
            isImportant: true,
        );
    }
}
