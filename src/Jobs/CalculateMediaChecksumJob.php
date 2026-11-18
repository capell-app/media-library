<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Jobs;

use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CalculateMediaChecksumJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int|string $mediaId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $media = CuratorMedia::query()->find($this->mediaId);

        if (! $media instanceof CuratorMedia || ! Schema::hasColumn('curator', 'capell_checksum_sha256')) {
            return;
        }

        $disk = $media->getAttribute('disk');
        $path = $media->getAttribute('path');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
            return;
        }

        $checksum = $this->checksum($disk, $path);

        if ($checksum === null) {
            return;
        }

        $media->forceFill(['capell_checksum_sha256' => $checksum])->saveQuietly();
    }

    /**
     * Streams the blob through an incremental hash so a large file never sits in memory.
     * Returns null when the blob is missing, so the row keeps no checksum rather than a wrong one.
     */
    private function checksum(string $disk, string $path): ?string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException('Unable to read the media blob for checksumming.');
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
