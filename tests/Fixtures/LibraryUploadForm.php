<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Tests\Fixtures;

use Awcodes\Curator\Actions\MultiUploadAction;
use Awcodes\Curator\Resources\Media\Schemas\MediaForm;
use Capell\MediaLibrary\Models\CuratorMedia;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;
use RuntimeException;

/** @property Schema $form */
final class LibraryUploadForm extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public ?CuratorMedia $record = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(?CuratorMedia $record = null): void
    {
        $this->record = $record;
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $form = config('curator.resource.schemas.form');
        throw_unless(is_string($form) && is_a($form, MediaForm::class, true), RuntimeException::class);

        return $schema->statePath('data')->components([$form::getUploaderField()]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        unset($data['originalFilename']);
        $this->record = $this->record instanceof CuratorMedia
            ? tap($this->record)->update($data)
            : CuratorMedia::query()->create($data);
    }

    public function bulkUploadAction(): MultiUploadAction
    {
        return MultiUploadAction::make('bulkUpload');
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}<x-filament-actions::modals /></div>';
    }
}
