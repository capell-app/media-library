<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Tests\Fixtures;

use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Core\Models\Page;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/** @property Schema $form */
final class PageMediaPickerForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public Page $record;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(Page $record): void
    {
        $this->record = $record;
        $this->form->fill(['meta' => $record->meta ?? []]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->model($this->record)->statePath('data')->components([
            MediaLibraryFileUpload::make('image')
                ->statePath('meta.image_id')
                ->key('page-image'),
        ]);
    }

    public function save(): void
    {
        $this->record->update($this->form->getState());
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}<x-filament-actions::modals /></div>';
    }
}
