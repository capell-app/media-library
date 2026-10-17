<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

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
        return parent::storeAs($path, $name, [
            'visibility' => $this->visibility,
            ...$this->parseOptions($options),
        ]);
    }
}
