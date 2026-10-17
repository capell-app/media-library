<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Filament\Actions;

use Awcodes\Curator\Actions\MultiUploadAction;
use Capell\MediaLibrary\Filament\Components\CuratorUploader;
use Override;

final class CuratorMultiUploadAction extends MultiUploadAction
{
    #[Override]
    public function configure(): static
    {
        CuratorUploader::during(function (): void {
            parent::configure();
        });

        return $this;
    }
}
