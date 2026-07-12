<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Jobs;

use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CalculateMediaChecksumJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int|string $mediaId) {}

    public function handle(): void
    {
        $media = CuratorMedia::query()->find($this->mediaId);

        if (! $media instanceof CuratorMedia) {
            return;
        }

        $disk = $media->getAttribute('disk');
        $path = $media->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return;
        }

        try {
            $contents = Storage::disk($disk)->get($path);
        } catch (Throwable) {
            return;
        }

        if (! is_string($contents)) {
            return;
        }

        $exif = is_array($media->getAttribute('exif')) ? $media->getAttribute('exif') : [];
        $exif['capell_checksum_sha256'] = hash('sha256', $contents);
        $media->forceFill(['exif' => $exif])->saveQuietly();
    }
}
