<?php

declare(strict_types=1);

use Awcodes\Curator\Components\Forms\CuratorPicker;
use Awcodes\Curator\Components\Forms\Uploader;
use Awcodes\Curator\Facades\Curator;
use Awcodes\Curator\Resources\Media\Schemas\MediaForm;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Capell\MediaLibrary\MediaLibraryServiceProvider;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\HostUploadForm;
use Capell\MediaLibrary\Tests\Fixtures\LibraryUploadForm;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

it('preserves configuration-only Curator storage choices', function (string $disk, string $visibility): void {
    config()->set('curator.default_disk', $disk);
    config()->set('curator.default_visibility', $visibility);

    $picker = resolve(MediaFieldFactory::class)->make('image');
    throw_unless($picker instanceof CuratorPicker, RuntimeException::class);
    expect($picker->getDiskName())->toBe($disk)
        ->and($picker->getVisibility())->toBe($visibility)
        ->and(MediaForm::getUploaderField()->getDiskName())->toBe($disk)
        ->and(MediaForm::getUploaderField()->getVisibility())->toBe($visibility);
})->with([['legacy-assets', 'private'], ['local', 'public'], ['public', 'private']]);

it('preserves facade storage choices made before backend registration', function (string $disk): void {
    Curator::disk($disk)->visibility('private');
    $provider = new MediaLibraryServiceProvider(app());
    new ReflectionMethod($provider, 'registerMediaBackend')->invoke($provider);

    $picker = resolve(MediaFieldFactory::class)->make('image');
    throw_unless($picker instanceof CuratorPicker, RuntimeException::class);
    expect($picker->getDiskName())->toBe($disk)
        ->and($picker->getVisibility())->toBe('private')
        ->and(MediaForm::getUploaderField()->getDiskName())->toBe($disk)
        ->and(MediaForm::getUploaderField()->getVisibility())->toBe('private');
})->with(['early-host-assets', 'public']);

it('preserves picker configureUsing disk and visibility', function (string $disk): void {
    Curator::disk('host-assets')->visibility('private');
    CuratorPicker::configureUsing(
        static fn (CuratorPicker $picker): CuratorPicker => $picker->disk($disk)->visibility('public'),
        during: function () use ($disk): void {
            $picker = resolve(MediaFieldFactory::class)->make('image');
            throw_unless($picker instanceof CuratorPicker, RuntimeException::class);
            expect($picker->getDiskName())->toBe($disk)
                ->and($picker->getVisibility())->toBe('public');
        },
    );
})->with(['field-assets', 'host-assets']);

it('stores library create uploads from nested file payloads with configured visibility', function (string $visibility): void {
    config()->set('capell.media_library.default_visibility', $visibility);
    $form = Livewire::test(LibraryUploadForm::class)
        ->set('data.file', [UploadedFile::fake()->image('created.png')]);
    $form->call('save')->assertHasNoErrors();
    $media = CuratorMedia::query()->sole();
    expect($media->visibility)->toBe($visibility)
        ->and(Storage::disk($media->disk)->getVisibility($media->path))->toBe($visibility);
})->with(['private', 'public']);

it('writes replacement library uploads with configured visibility and retains unrelated media', function (string $visibility): void {
    $form = Livewire::test(LibraryUploadForm::class)
        ->set('data.file', [UploadedFile::fake()->image('original.png')])
        ->call('save')->assertHasNoErrors();
    $media = CuratorMedia::query()->sole();
    $originalName = $media->name;
    $form = Livewire::test(LibraryUploadForm::class)
        ->set('data.file', [UploadedFile::fake()->image('unrelated.png')])
        ->call('save')->assertHasNoErrors();
    $unrelated = CuratorMedia::query()->whereKeyNot($media->getKey())->sole();
    $unrelatedAttributes = $unrelated->getAttributes();
    $unrelatedBytes = Storage::disk($unrelated->disk)->get($unrelated->path);
    config()->set('capell.media_library.default_visibility', $visibility);

    Livewire::test(LibraryUploadForm::class, ['record' => $media])
        ->set('data.file', [UploadedFile::fake()->image('replacement.jpg')])
        ->call('save')->assertHasNoErrors();

    expect($media->refresh()->visibility)->toBe($visibility)
        ->and($media->name)->toBe($originalName)
        ->and($media->ext)->toBe('jpg')
        ->and(Storage::disk($media->disk)->getVisibility($media->path))->toBe($visibility)
        ->and($unrelated->refresh()->getAttributes())->toBe($unrelatedAttributes)
        ->and(Storage::disk($unrelated->disk)->get($unrelated->path))->toBe($unrelatedBytes)
        ->and(Storage::disk($unrelated->disk)->getVisibility($unrelated->path))->toBe('public');
})->with(['private', 'public']);

it('executes the real bulk upload action with configured visibility', function (string $visibility): void {
    Gate::before(static fn (): bool => true);
    config()->set('capell.media_library.default_visibility', $visibility);
    Uploader::configureUsing(
        static fn (Uploader $uploader): Uploader => $uploader->afterStateUpdated(function (Uploader $component) use ($visibility): void {
            foreach ((array) $component->getRawState() as $file) {
                if (is_array($file)) {
                    $disk = $file['disk'] ?? null;
                    $path = $file['path'] ?? null;
                    throw_unless(is_string($disk) && is_string($path), RuntimeException::class);
                    expect(Storage::disk($disk)->getVisibility($path))->toBe($visibility);
                }
            }
        }),
        during: fn () => Livewire::test(LibraryUploadForm::class)
            ->mountAction('bulkUpload')
            ->set('mountedActions.0.data.files', [UploadedFile::fake()->image('bulk-one.png'), UploadedFile::fake()->image('bulk-two.png')])
            ->callMountedAction()
            ->assertHasNoErrors(),
    );

    expect(CuratorMedia::query()->count())->toBe(2);
    foreach (CuratorMedia::all() as $media) {
        expect($media->disk)->toBe('public')
            ->and($media->visibility)->toBe($visibility)
            ->and(Storage::disk($media->disk)->getVisibility($media->path))->toBe($visibility);
    }
})->with(['private', 'public']);

it('keeps explicit private storage from a host save callback in the bulk upload action', function (): void {
    Gate::before(static fn (): bool => true);
    config()->set('capell.media_library.default_visibility', 'public');
    $stored = [];
    Uploader::configureUsing(
        static function (Uploader $uploader) use (&$stored): void {
            $uploader->saveUploadedFileUsing(static function (TemporaryUploadedFile $file) use (&$stored): array {
                $path = $file->storeAs('host-private', $file->getClientOriginalName(), ['disk' => 'public', 'visibility' => 'private']);
                throw_unless(is_string($path), RuntimeException::class);
                $stored[] = $path;

                return [
                    'disk' => 'public',
                    'visibility' => 'private',
                    'name' => pathinfo($path, PATHINFO_FILENAME),
                    'path' => $path,
                    'type' => 'image/png',
                    'ext' => 'png',
                ];
            });
        },
        during: fn () => Livewire::test(LibraryUploadForm::class)
            ->mountAction('bulkUpload')
            ->set('mountedActions.0.data.files', [UploadedFile::fake()->image('host-private.png')])
            ->callMountedAction(),
    );

    expect($stored)->not->toBeEmpty();
    foreach ($stored as $path) {
        expect(Storage::disk('public')->getVisibility($path))->toBe('private');
    }
});

it('mounts the bulk upload action when a host clears the save callback', function (): void {
    Gate::before(static fn (): bool => true);
    Uploader::configureUsing(
        static fn (Uploader $uploader): Uploader => $uploader->saveUploadedFileUsing(null),
        during: fn () => Livewire::test(LibraryUploadForm::class)
            ->mountAction('bulkUpload')
            ->assertActionMounted('bulkUpload'),
    );
});

it('stores real picker uploads in a fresh process with the Laravel local filesystem default', function (?string $curatorDisk): void {
    $process = new Process([
        PHP_BINARY, '-d', 'memory_limit=1536M', 'vendor/bin/pest',
        'packages/media-library/tests/Feature/CuratorPickerTest.php',
        '--configuration=phpunit.xml', '--colors=never', '--compact',
        $curatorDisk === null ? '--filter=stores picker uploads publicly even' : '--filter=stores picker uploads on the environment Curator disk',
    ], dirname(__DIR__, 4), [
        'FILESYSTEM_DISK' => 'local',
        'CURATOR_DEFAULT_DISK' => $curatorDisk ?? false,
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'DB_URL' => '',
    ]);
    $process->setTimeout(120);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
})->with([null, 'local', 'environment-assets']);

it('executes host uploader configureUsing save callbacks for real uploads', function (): void {
    $called = false;
    Uploader::configureUsing(
        function (Uploader $uploader) use (&$called): void {
            $uploader->saveUploadedFileUsing(function (TemporaryUploadedFile $file) use (&$called): array {
                $called = true;
                $path = $file->storeAs('host-storage', 'custom.png', 'public');

                return ['path' => $path];
            });
        },
        during: fn () => Livewire::test(HostUploadForm::class)
            ->set('data.file', [UploadedFile::fake()->image('host.png')])
            ->call('save')->assertHasNoErrors(),
    );

    expect($called)->toBeTrue();
    Storage::disk('public')->assertExists('host-storage/custom.png');
});
