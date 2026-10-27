<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions;

use Capell\MediaLibrary\Data\MediaRepairPreviewData;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\MediaHealthAuthorization;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** @method static MediaRepairPreviewData run(CuratorMedia $media) */
final class BuildMediaRepairPreviewAction
{
    use AsFake;
    use AsObject;

    public function handle(CuratorMedia $media): MediaRepairPreviewData
    {
        MediaHealthAuthorization::authorizeMetadataUpdate(auth()->user());

        $disk = $media->getAttribute('disk');
        $path = $media->getAttribute('path');
        $type = $media->getAttribute('type');

        if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === ''
            || ! in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true)
            || $media->getAttribute('visibility') === 'private') {
            return new MediaRepairPreviewData;
        }

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path) || $storage->getVisibility($path) !== 'public') {
                return new MediaRepairPreviewData;
            }

            $url = $media->getUrl();
            $scheme = parse_url($url, PHP_URL_SCHEME);

            if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1
                || (! in_array($scheme, ['http', 'https'], true) && ! (str_starts_with($url, '/') && ! str_starts_with($url, '//')))) {
                return new MediaRepairPreviewData;
            }

            return new MediaRepairPreviewData($url);
        } catch (Throwable) {
            return new MediaRepairPreviewData;
        }
    }
}
