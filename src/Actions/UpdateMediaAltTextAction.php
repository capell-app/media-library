<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions;

use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\MediaHealthAuthorization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class UpdateMediaAltTextAction
{
    use AsFake;
    use AsObject;

    public function handle(?Authenticatable $actor, CuratorMedia $media, string $alt): void
    {
        MediaHealthAuthorization::authorizeMetadataUpdate($actor);
        $alt = trim($alt);
        Validator::make(['alt' => $alt], ['alt' => ['required', 'string', 'max:255']])->validate();
        $media->forceFill(['alt' => $alt])->save();
    }
}
