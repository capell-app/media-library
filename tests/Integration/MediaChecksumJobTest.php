<?php

declare(strict_types=1);

use Capell\MediaLibrary\Jobs\CalculateMediaChecksumJob;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('queues checksum persistence after media creation', function (): void {
    Queue::fake();
    Storage::fake('public');
    Storage::disk('public')->put('media/checksum.txt', 'checksum payload');

    $media = CuratorMedia::query()->create([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => 'checksum',
        'path' => 'media/checksum.txt',
        'type' => 'text/plain',
        'ext' => 'txt',
    ]);

    Queue::assertPushed(
        CalculateMediaChecksumJob::class,
        fn (CalculateMediaChecksumJob $job): bool => $job->mediaId === $media->getKey(),
    );
});

it('persists a sha256 checksum without reading the blob in the upload request', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/checksum.txt', 'checksum payload');

    $media = CuratorMedia::query()->create([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => 'checksum',
        'path' => 'media/checksum.txt',
        'type' => 'text/plain',
        'ext' => 'txt',
    ]);

    (new CalculateMediaChecksumJob($media->getKey()))->handle();

    expect($media->fresh()?->getAttribute('exif')['capell_checksum_sha256'] ?? null)
        ->toBe(hash('sha256', 'checksum payload'));
});
