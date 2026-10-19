<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\MediaLibrary\Actions\RestrictMediaWriteAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Override;
use RuntimeException;

/** Carries the field's visibility into Curator's non-SVG storage call. */
final class VisibilityAwareTemporaryUpload extends TemporaryUploadedFile
{
    public function __construct(TemporaryUploadedFile $file, private readonly string $visibility)
    {
        $disk = $file->disk;
        throw_unless(is_string($disk), RuntimeException::class);
        parent::__construct($file->getFilename(), $disk);
    }

    /**
     * @param  string  $path
     * @param  string|null  $name
     * @param  array<string, mixed>|string  $options
     */
    #[Override]
    public function storeAs($path, $name = null, $options = []): string|false
    {
        // The field visibility is a fallback: an explicit option from a host
        // save callback wins.
        $options = [
            'visibility' => $this->visibility,
            ...$this->parseOptions($options),
        ];
        $disk = $options['disk'] ?? config('filesystems.default');
        throw_unless(is_string($disk), RuntimeException::class);
        $target = trim($path . '/' . $name, '/');
        if ($options['visibility'] === 'private' && Storage::disk($disk)->exists($target)) {
            throw_unless(Storage::disk($disk)->setVisibility($target, 'private'), RuntimeException::class);
        }

        return (new RestrictMediaWriteAction)->handle(fn (): string|false => parent::storeAs($path, $name, $options));
    }
}
