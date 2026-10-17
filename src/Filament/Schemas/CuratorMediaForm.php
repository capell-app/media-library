<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Schemas;

use Awcodes\Curator\Components\Forms\Uploader;
use Awcodes\Curator\Resources\Media\Schemas\MediaForm;
use Capell\MediaLibrary\Filament\Components\CuratorUploader;
use Override;

final class CuratorMediaForm extends MediaForm
{
    #[Override]
    public static function getUploaderField(): Uploader
    {
        return CuratorUploader::during(parent::getUploaderField(...));
    }
}
