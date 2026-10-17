<?php

declare(strict_types=1);

use Awcodes\Curator\Components\Forms\CuratorPicker;
use Awcodes\Curator\Facades\Curator;
use Awcodes\Curator\Facades\Glide;
use Awcodes\Curator\Resources\Media\Schemas\MediaForm;
use Capell\Core\Models\Page;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\PageMediaPickerForm;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('opens the page image picker with its real validation rules and lists existing media', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'Uploader']);
    $media = $owner->addMediaFromUploadedFile(UploadedFile::fake()->image('shared-hero.jpg'), 'image');
    throw_unless($media instanceof CuratorMedia, RuntimeException::class);
    $page = Page::factory()->create();

    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page])
        ->mountAction(TestAction::make('launchPanel')->schemaComponent('page-image'))
        ->assertActionMounted(TestAction::make('launchPanel')->schemaComponent('page-image'));
    $picker = pageMediaPickerField($form->instance());
    $settings = pageMediaPickerSettings($picker);

    Livewire::test('curator-panel', ['settings' => $settings])
        ->set('search', 'shared')
        ->assertSee('shared-hero')
        ->assertSet('files.0.id', $media->getKey());

    expect(CuratorMedia::query()->count())->toBe(1);
});

it('attaches one library item to two pages through the panel and picker without moving it', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'Uploader']);
    $media = $owner->addMediaFromUploadedFile(UploadedFile::fake()->image('reusable-hero.jpg'), 'image');
    throw_unless($media instanceof CuratorMedia, RuntimeException::class);
    $pages = Page::factory()->count(2)->create();

    foreach ($pages as $page) {
        $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
        $picker = pageMediaPickerField($form->instance());
        $settings = pageMediaPickerSettings($picker);
        $panel = Livewire::test('curator-panel', ['settings' => $settings])
            ->set('search', 'reusable')
            ->assertSee('reusable-hero')
            ->set('selected', [['id' => $media->getKey()]])
            ->callAction('insertMedia');
        $payload = [];
        $panel->assertDispatched('insert-media', function (string $event, array $parameters) use (&$payload, $media): bool {
            $payload = $parameters[0];

            return data_get($payload, 'media.0.id') === $media->getKey();
        });

        $form->call('callSchemaComponentMethod', $picker->getKey(), 'updateState', [$payload])
            ->call('save');

        expect(data_get($page->refresh()->meta, 'image_id'))->toBe($media->getKey());
    }

    expect(CuratorMedia::query()->count())->toBe(1)
        ->and($owner->refresh()->image_id)->toBe($media->getKey())
        ->and($pages[0]->fresh()?->meta['image_id'])->toBe($pages[1]->fresh()?->meta['image_id']);
});

it('keeps picker selection validation on the server and file validation in panel snapshots', function (): void {
    $page = Page::factory()->create();
    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
    $picker = pageMediaPickerField($form->instance());
    $picker->rules(['dimensions:min_width=100', 'image']);

    $settings = pageMediaPickerSettings($picker);

    $rules = $settings['rules'];
    throw_unless(is_array($rules), RuntimeException::class);
    expect(collect($rules)->contains(fn (mixed $rule): bool => $rule instanceof Closure))->toBeTrue();

    Livewire::test('curator-panel', ['settings' => $settings])
        ->assertSet('validationRules', ['dimensions:min_width=100', 'image'])
        ->assertSet('rules', ['dimensions:min_width=100', 'image'])
        ->assertSet('settings.rules', ['dimensions:min_width=100', 'image'])
        ->set('search', 'round trip');

    $owner = TestCuratorOwner::query()->create(['name' => 'Validation uploader']);
    $owner->addMediaFromUploadedFile(UploadedFile::fake()->image('validation.jpg'), 'image');

    $invalidSelection = CuratorMedia::query()->sole()->toArray();
    $invalidSelection['id'] = 999999;

    $form->set('data.meta.image_id', [$invalidSelection])
        ->call('save')
        ->assertHasErrors('data.meta.image_id');

    expect(data_get($page->refresh()->meta, 'image_id'))->toBeNull();
});

it('rejects uploads that violate the picker file rules through the panel adapter', function (): void {
    $page = Page::factory()->create();
    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
    $picker = pageMediaPickerField($form->instance());
    $picker->rules(['dimensions:min_width=100', 'image']);

    $storedFiles = Storage::disk('public')->allFiles();

    Livewire::test('curator-panel', ['settings' => pageMediaPickerSettings($picker)])
        ->set('search', 'round trip')
        ->set('panelData.files_to_add', [UploadedFile::fake()->image('too-small.jpg', 50, 50)])
        ->callAction('addFiles')
        ->assertHasErrors(['panelData.files_to_add' => __('validation.dimensions', ['attribute' => mb_strtolower(__('curator::forms.fields.file'))])]);

    expect(CuratorMedia::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe($storedFiles);
});

it('stores picker uploads publicly even when the application default disk is private and serves their thumbnails', function (): void {
    config()->set('filesystems.default', 'local');
    Storage::fake('local');
    $page = Page::factory()->create();
    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
    $picker = pageMediaPickerField($form->instance());

    Livewire::test('curator-panel', ['settings' => pageMediaPickerSettings($picker)])
        ->set('panelData.files_to_add', [UploadedFile::fake()->image('public-picker.png')])
        ->callAction('addFiles')
        ->assertHasNoErrors();

    $media = CuratorMedia::query()->sole();
    expect($media->disk)->toBe('public')
        ->and($media->visibility)->toBe('public')
        ->and(Storage::disk('public')->getVisibility($media->path))->toBe('public');
    Storage::disk('public')->assertExists($media->path);
    Storage::disk('local')->assertMissing($media->path);

    $this->get($media->thumbnail_url)->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get('/curator/' . $media->path)->assertForbidden();
});

it('applies configured storage to library upload fields and standalone panel uploads', function (string $visibility): void {
    config()->set('capell.media_library.default_disk', 'media-assets');
    config()->set('capell.media_library.default_visibility', $visibility);
    Storage::fake('media-assets');

    $uploader = MediaForm::getUploaderField();
    expect($uploader->getDiskName())->toBe('media-assets')
        ->and($uploader->getVisibility())->toBe($visibility);

    $page = Page::factory()->create();
    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
    $picker = pageMediaPickerField($form->instance());
    expect($picker->getDiskName())->toBe('media-assets')
        ->and($picker->getVisibility())->toBe($visibility);

    Livewire::test('curator-panel', ['settings' => ['acceptedFileTypes' => ['image/png']]])
        ->set('panelData.files_to_add', [UploadedFile::fake()->image('configured.png')])
        ->callAction('addFiles')
        ->assertHasNoErrors();

    $media = CuratorMedia::query()->sole();
    expect($media->disk)->toBe('media-assets')
        ->and($media->visibility)->toBe($visibility)
        ->and(Storage::disk('media-assets')->getVisibility($media->path))->toBe($visibility);
    $thumbnail = $media->thumbnail_url;
    $response = $this->get($thumbnail)->assertOk()->assertHeader('Content-Type', 'image/webp');

    if ($visibility === 'private') {
        expect($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');
        $this->get(Glide::getUrl($media->path))->assertNotFound();
        $this->travel(6)->minutes();
        $this->get($thumbnail)->assertForbidden();
    }
})->with(['public', 'private']);

it('can explicitly delegate the upload disk to Curator configuration', function (): void {
    config()->set('capell.media_library.default_disk');
    config()->set('curator.default_disk', 'curator-assets');

    $page = Page::factory()->create();
    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
    expect(pageMediaPickerField($form->instance())->getDiskName())->toBe('curator-assets')
        ->and(MediaForm::getUploaderField()->getDiskName())->toBe('curator-assets');
});

it('respects host Curator defaults and explicit picker and panel storage overrides', function (): void {
    Curator::disk('host-assets')->visibility('private');
    $page = Page::factory()->create();
    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
    $picker = pageMediaPickerField($form->instance());
    expect($picker->getDiskName())->toBe('host-assets')
        ->and($picker->getVisibility())->toBe('private');
    $picker->disk('public')->visibility('public');
    expect(pageMediaPickerSettings($picker))->toMatchArray(['diskName' => 'public', 'visibility' => 'public']);

    Livewire::test('curator-panel', ['settings' => ['diskName' => 'public', 'visibility' => 'public', 'acceptedFileTypes' => ['image/png']]])
        ->set('panelData.files_to_add', [UploadedFile::fake()->image('explicit.png')])
        ->callAction('addFiles')
        ->assertHasNoErrors();

    $media = CuratorMedia::query()->sole();
    expect($media->disk)->toBe('public')->and($media->visibility)->toBe('public');
});

it('keeps saved local media visible with its original visibility and disk', function (string $visibility): void {
    Storage::fake('local');
    $owner = TestCuratorOwner::query()->create(['name' => 'Legacy uploader']);
    $media = $owner->addMediaFromUploadedFile(UploadedFile::fake()->image('legacy.png'), 'image', 'private');
    throw_unless($media instanceof CuratorMedia, RuntimeException::class);
    // Reproduce rows uploaded to local before the public upload defaults existed.
    Storage::disk('local')->setVisibility($media->path, $visibility);
    $media->update(['visibility' => $visibility]);
    $page = Page::factory()->create(['meta' => ['image_id' => $media->getKey()]]);
    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
    $picker = pageMediaPickerField($form->instance());
    $state = $picker->getState();
    throw_unless(is_array($state), RuntimeException::class);
    expect(array_column($state, 'id'))->toBe([$media->getKey()]);
    $item = array_values($state)[0];
    throw_unless(is_array($item), RuntimeException::class);
    $thumbnail = $item['thumbnail_url'];
    throw_unless(is_string($thumbnail), RuntimeException::class);
    $this->get($thumbnail)->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get(Glide::getUrl($media->path))->assertStatus($visibility === 'private' ? 404 : 200);
    $form->call('save')->assertHasNoErrors();

    Livewire::test('curator-panel', ['settings' => pageMediaPickerSettings($picker)])
        ->assertDontSee('legacy')
        ->assertSet('files', []);
    expect($media->refresh()->disk)->toBe('local')
        ->and($media->visibility)->toBe($visibility)
        ->and(Storage::disk('local')->getVisibility($media->path))->toBe($visibility)
        ->and(data_get($page->refresh()->meta, 'image_id'))->toBe($media->getKey());
})->with(['public', 'private']);

function pageMediaPickerField(mixed $component): CuratorPicker
{
    throw_unless($component instanceof PageMediaPickerForm, RuntimeException::class);
    $picker = $component->form->getComponent('page-image');
    throw_unless($picker instanceof CuratorPicker, RuntimeException::class);

    return $picker;
}

/** @return array<string, mixed> */
function pageMediaPickerSettings(CuratorPicker $picker): array
{
    $action = $picker->getAction('launchPanel');
    throw_unless($action instanceof Action, RuntimeException::class);
    $content = $action->getModalContent();
    throw_unless($content instanceof View, RuntimeException::class);
    $settings = $content->getData()['settings'];
    throw_unless(is_array($settings), RuntimeException::class);

    return capell_string_keyed_array($settings);
}

it('stores picker uploads on the environment Curator disk', function (): void {
    $environmentDisk = getenv('CURATOR_DEFAULT_DISK');
    $disk = $environmentDisk === false ? 'public' : $environmentDisk;
    Storage::fake($disk);
    $page = Page::factory()->create();
    $form = Livewire::test(PageMediaPickerForm::class, ['record' => $page]);
    $picker = pageMediaPickerField($form->instance());

    Livewire::test('curator-panel', ['settings' => pageMediaPickerSettings($picker)])
        ->set('panelData.files_to_add', [UploadedFile::fake()->image('environment.png')])
        ->callAction('addFiles')->assertHasNoErrors();

    $media = CuratorMedia::query()->sole();
    expect($media->disk)->toBe($disk)
        ->and($media->visibility)->toBe('public');
    Storage::disk($disk)->assertExists($media->path);
    if ($disk !== 'public') {
        Storage::disk('public')->assertMissing($media->path);
    }
});
