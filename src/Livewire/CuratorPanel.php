<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Livewire;

use Awcodes\Curator\Components\Modals\CuratorPanel as BaseCuratorPanel;
use Awcodes\Curator\Facades\Curator;
use Capell\MediaLibrary\Filament\Components\CuratorUploader;
use Filament\Schemas\Schema;
use Override;

final class CuratorPanel extends BaseCuratorPanel
{
    #[Override]
    public function form(Schema $schema): Schema
    {
        return CuratorUploader::during(fn (): Schema => parent::form($schema));
    }

    #[Override]
    public function mount(): void
    {
        $this->settings = [
            'diskName' => Curator::getDiskName(),
            'visibility' => Curator::getVisibility(),
            ...($this->settings ?? []),
        ];

        parent::mount();

        // Picker selection rules include closures. Only the panel's file rules belong in its Livewire snapshot.
        $this->rules = $this->validationRules;
        $this->settings['rules'] = $this->validationRules;
    }
}
