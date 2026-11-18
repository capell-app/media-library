<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Livewire;

use Awcodes\Curator\Components\Modals\CuratorPanel as BaseCuratorPanel;
use Awcodes\Curator\Facades\Curator;
use Capell\MediaLibrary\Filament\Components\CuratorUploader;
use Capell\MediaLibrary\Support\CuratorMediaListContext;
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

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Override]
    public function getFiles(int $page = 0, bool $excludeSelected = false): array
    {
        return resolve(CuratorMediaListContext::class)->during(fn (): array => parent::getFiles($page, $excludeSelected));
    }

    #[Override]
    public function updatedSearch(): void
    {
        resolve(CuratorMediaListContext::class)->during(fn () => parent::updatedSearch());
    }
}
