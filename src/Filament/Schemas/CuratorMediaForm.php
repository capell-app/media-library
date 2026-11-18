<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Schemas;

use Awcodes\Curator\Components\Forms\Uploader;
use Awcodes\Curator\Resources\Media\Schemas\MediaForm;
use Capell\MediaLibrary\Filament\Components\CuratorUploader;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Override;

final class CuratorMediaForm extends MediaForm
{
    #[Override]
    public static function getUploaderField(): Uploader
    {
        return CuratorUploader::during(parent::getUploaderField(...));
    }

    /**
     * Editors record who owns the rights to an asset, which is what the Media Health missing rights report reads.
     *
     * @return array<int, Component>
     */
    #[Override]
    public static function getAdditionalInformationFormSchema(): array
    {
        return [
            ...parent::getAdditionalInformationFormSchema(),
            TextInput::make('exif.copyright')
                ->label(__('capell-media-library::package.fields.copyright'))
                ->helperText(__('capell-media-library::package.fields.copyright_help'))
                ->maxLength(255)
                ->hiddenOn('create'),
            TextInput::make('exif.licence')
                ->label(__('capell-media-library::package.fields.licence'))
                ->helperText(__('capell-media-library::package.fields.licence_help'))
                ->maxLength(255)
                ->hiddenOn('create'),
        ];
    }
}
