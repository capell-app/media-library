<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\DashboardReports\BuildMediaHealthQueryAction;
use Capell\MediaLibrary\Actions\MigrateSpatieMediaToCuratorAction;
use Capell\MediaLibrary\Data\MigrateSpatieMediaInput;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\MediaLibrary\Filament\Pages\Tables\MediaHealthTable;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (! Schema::hasTable('media')) {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->timestamps();
        });
    }
});

it('filters unsafe media health owner foreign key configuration', function (): void {
    $mediaId = DB::table('curator')->insertGetId([
        'disk' => 'public',
        'directory' => '',
        'visibility' => 'public',
        'name' => 'unused',
        'path' => 'unused.jpg',
        'width' => null,
        'height' => null,
        'size' => 100,
        'type' => 'image/jpeg',
        'ext' => 'jpg',
        'alt' => 'Useful alt',
        'title' => null,
        'description' => null,
        'caption' => null,
        'exif' => null,
        'curations' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $records = BuildMediaHealthQueryAction::run([
        ['table' => 'missing_table', 'column' => 'image_id'],
        ['table' => 'test_curator_owners;drop', 'column' => 'image_id'],
        ['table' => 'test_curator_owners', 'column' => 'missing_column'],
    ])->get();

    expect($records->pluck('id')->all())->not->toContain($mediaId);
});

it('records migration warnings for missing owner models and unsupported collections', function (): void {
    DB::table('media')->insert([
        [
            'model_type' => 'App\\Models\\MissingMediaOwner',
            'model_id' => 1,
            'uuid' => null,
            'collection_name' => 'image',
            'name' => 'missing-owner',
            'file_name' => 'missing-owner.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 100,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'order_column' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'model_type' => TestCuratorOwner::class,
            'model_id' => 1,
            'uuid' => null,
            'collection_name' => 'gallery',
            'name' => 'unsupported',
            'file_name' => 'unsupported.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => null,
            'size' => 100,
            'manipulations' => '[]',
            'custom_properties' => '[]',
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'order_column' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $result = MigrateSpatieMediaToCuratorAction::run(new MigrateSpatieMediaInput(dryRun: true, chunkSize: 1));

    expect($result->processed)->toBe(2)
        ->and($result->created)->toBe(0)
        ->and($result->warnings)->toHaveCount(2)
        ->and($result->warnings[0])->toContain('could not be instantiated')
        ->and($result->warnings[1])->toContain('does not exist on table');
});

it('normalizes command input and prints migration warnings', function (): void {
    DB::table('media')->insert([
        'model_type' => 'App\\Models\\MissingMediaOwner',
        'model_id' => 1,
        'uuid' => null,
        'collection_name' => 'hero',
        'name' => 'missing-owner',
        'file_name' => 'missing-owner.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'conversions_disk' => null,
        'size' => 100,
        'manipulations' => '[]',
        'custom_properties' => '[]',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
        'order_column' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('capell:media-migrate-to-curator', [
        '--dry-run' => true,
        '--collection' => ['hero'],
        '--owner-type' => 'App\\Models\\MissingMediaOwner',
        '--chunk' => 0,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('[Dry run] No data will be written.')
        ->expectsOutputToContain('could not be instantiated');
});

it('declares media health page labels table and model casts', function (): void {
    expect(MediaHealthPage::getNavigationLabel())->toBeString()
        ->and((new MediaHealthPage)->getTitle())->toBeString()
        ->and((new MediaHealthPage)->getSubheading())->toBeString()
        ->and((new CuratorMedia)->getTable())->toBe('curator');
});

it('projects and writes successful spatie to curator migrations with metadata', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'Migration Owner']);

    mediaLibraryCoverageInsertSpatieRow([
        'model_type' => TestCuratorOwner::class,
        'model_id' => $owner->getKey(),
        'collection_name' => 'image',
        'name' => 'hero-image',
        'file_name' => 'hero-image.jpg',
        'mime_type' => 'image/jpeg',
        'custom_properties' => json_encode([
            'alt_text' => 'Hero alt',
            'title' => 'Hero title',
            'description' => 'Hero description',
            'caption' => 'Hero caption',
            'dimensions' => ['width' => '1200', 'height' => '800'],
            'exif' => ['camera' => 'Test body'],
            'curations' => [
                ['key' => 'hero', 'x' => 10],
            ],
            'kept' => 'metadata',
        ], JSON_THROW_ON_ERROR),
        'manipulations' => json_encode(['thumb' => true], JSON_THROW_ON_ERROR),
        'generated_conversions' => json_encode(['thumb' => true], JSON_THROW_ON_ERROR),
        'responsive_images' => json_encode(['hero' => ['srcset' => 'hero.jpg 1x']], JSON_THROW_ON_ERROR),
    ]);

    $dryRunResult = MigrateSpatieMediaToCuratorAction::run(new MigrateSpatieMediaInput(dryRun: true, chunkSize: 5));

    expect($dryRunResult->processed)->toBe(1)
        ->and($dryRunResult->created)->toBe(1)
        ->and($dryRunResult->ownersUpdated)->toBe(1)
        ->and(DB::table('curator')->count())->toBe(0)
        ->and($owner->fresh()->image_id)->toBeNull();

    $result = MigrateSpatieMediaToCuratorAction::run(new MigrateSpatieMediaInput(dryRun: false, chunkSize: 5));
    $curatorRow = DB::table('curator')->first();

    expect($result->processed)->toBe(1)
        ->and($result->created)->toBe(1)
        ->and($result->ownersUpdated)->toBe(1)
        ->and($result->warnings)->toBe([])
        ->and($curatorRow)->not->toBeNull()
        ->and($curatorRow->alt)->toBe('Hero alt')
        ->and($curatorRow->title)->toBe('Hero title')
        ->and($curatorRow->description)->toBe('Hero description')
        ->and($curatorRow->caption)->toBe('Hero caption')
        ->and($curatorRow->width)->toBe(1200)
        ->and($curatorRow->height)->toBe(800)
        ->and($owner->fresh()->image_id)->toBe($curatorRow->id);

    $secondResult = MigrateSpatieMediaToCuratorAction::run(new MigrateSpatieMediaInput(dryRun: false, chunkSize: 5));

    expect($secondResult->processed)->toBe(1)
        ->and($secondResult->created)->toBe(0)
        ->and($secondResult->skipped)->toBe(1)
        ->and($secondResult->ownersUpdated)->toBe(0);
});

it('filters migration rows and handles invalid curation metadata', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'Filtered Owner']);

    mediaLibraryCoverageInsertSpatieRow([
        'model_type' => TestCuratorOwner::class,
        'model_id' => $owner->getKey(),
        'collection_name' => 'image',
        'name' => 'invalid-curations',
        'file_name' => 'invalid-curations.png',
        'mime_type' => 'image/png',
        'custom_properties' => json_encode([
            'curations' => ['not-an-array-entry'],
            'original' => ['width' => 640, 'height' => 480],
        ], JSON_THROW_ON_ERROR),
    ]);

    mediaLibraryCoverageInsertSpatieRow([
        'model_type' => TestCuratorOwner::class,
        'model_id' => $owner->getKey(),
        'collection_name' => 'other',
        'name' => 'filtered-out',
        'file_name' => 'filtered-out.png',
        'mime_type' => 'image/png',
    ]);

    $filteredResult = MigrateSpatieMediaToCuratorAction::run(new MigrateSpatieMediaInput(
        dryRun: false,
        collections: ['image'],
        chunkSize: 1,
        ownerType: TestCuratorOwner::class,
    ));
    $curatorRow = DB::table('curator')->first();

    expect($filteredResult->processed)->toBe(1)
        ->and($filteredResult->created)->toBe(1)
        ->and($curatorRow->width)->toBe(640)
        ->and($curatorRow->height)->toBe(480)
        ->and($curatorRow->curations)->toBeNull();
});

it('implements curator media contract fallbacks', function (): void {
    $media = new CuratorMedia([
        'disk' => 'public',
        'directory' => 'media',
        'visibility' => 'public',
        'name' => '',
        'pretty_name' => 'Pretty name',
        'path' => 'media/pretty.jpg',
        'width' => 320,
        'height' => 200,
        'size' => 100,
        'type' => 'image/jpeg',
        'ext' => 'jpg',
        'alt' => 'Alt text',
        'title' => 'Title text',
        'description' => 'Description text',
        'caption' => 'Caption text',
    ]);

    expect($media->getFullUrl())->toBe($media->getUrl())
        ->and($media->getAvailableFullUrl(['thumb']))->toBe($media->getUrl('thumb'))
        ->and($media->getSrcset())->toContain('200w')
        ->and($media->hasResponsiveImages())->toBeTrue()
        ->and($media->hasConversion('thumb'))->toBeTrue()
        ->and($media->getName())->toBe('Title text')
        ->and($media->getPath())->toBe('media/pretty.jpg')
        ->and($media->getMimeType())->toBe('image/jpeg')
        ->and($media->getWidth())->toBe(320)
        ->and($media->getHeight())->toBe(200)
        ->and($media->getCustomProperty('alt'))->toBe('Alt text')
        ->and($media->getCustomProperty('title'))->toBe('Title text')
        ->and($media->getCustomProperty('description'))->toBe('Description text')
        ->and($media->getCustomProperty('caption'))->toBe('Caption text')
        ->and($media->getCustomProperty('width'))->toBe(320)
        ->and($media->getCustomProperty('height'))->toBe(200)
        ->and($media->getCustomProperty('missing', 'fallback'))->toBe('fallback');
});

it('resolves curator media trait columns empty collections and missing ids', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'Trait Owner']);

    expect(TestCuratorOwner::curatorMediaColumn('socialImage'))->toBe('social_image_id')
        ->and($owner->curatorMediaRelation('image')->getForeignKeyName())->toBe('image_id')
        ->and($owner->getFirstMedia('image'))->toBeNull()
        ->and($owner->getMedia('image'))->toHaveCount(0)
        ->and($owner->getFirstMediaUrl('image'))->toBe('');
});

it('stores uploaded curator media and clears the owner collection', function (): void {
    $owner = TestCuratorOwner::query()->create(['name' => 'Upload Owner']);

    $media = $owner->addMediaFromUploadedFile(
        UploadedFile::fake()->image('Profile Photo.jpg', 120, 80)->size(42),
        'image',
    );

    expect($media)->toBeInstanceOf(CuratorMedia::class);

    throw_unless($media instanceof CuratorMedia, RuntimeException::class, 'Uploaded media must be curator media.');

    $freshMedia = $owner->fresh()->getFirstMedia('image');

    expect($freshMedia)->toBeInstanceOf(CuratorMedia::class);

    throw_unless($freshMedia instanceof CuratorMedia, RuntimeException::class, 'Fresh owner media must be curator media.');

    expect($media->disk)->toBe('public')
        ->and($media->directory)->toBe('media')
        ->and($media->name)->toBe('Profile Photo')
        ->and($media->ext)->toBe('jpg')
        ->and($owner->fresh()->image_id)->toBe($media->getKey())
        ->and($freshMedia->getKey())->toBe($media->getKey())
        ->and($owner->fresh()->getMedia('image'))->toHaveCount(1);

    $returnedOwner = $owner->fresh()->clearMediaCollection('image');

    expect($returnedOwner)->toBeInstanceOf(TestCuratorOwner::class)
        ->and($returnedOwner->fresh()->image_id)->toBeNull();
});

it('builds the media health table columns and default sort', function (): void {
    $table = MediaHealthTable::configure(mediaLibraryCoverageTable());

    expect($table->getColumns())->toHaveCount(5)
        ->and(array_keys($table->getColumns()))->toBe([
            'name',
            'size',
            'usage_count',
            'type',
            'updated_at',
        ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function mediaLibraryCoverageInsertSpatieRow(array $overrides): void
{
    DB::table('media')->insert(array_merge([
        'model_type' => TestCuratorOwner::class,
        'model_id' => 1,
        'uuid' => null,
        'collection_name' => 'image',
        'name' => 'image',
        'file_name' => 'image.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'conversions_disk' => null,
        'size' => 100,
        'manipulations' => '[]',
        'custom_properties' => '[]',
        'generated_conversions' => '[]',
        'responsive_images' => '[]',
        'order_column' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function mediaLibraryCoverageTable(): Table
{
    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('makeFilamentTranslatableContentDriver')->andReturn(null);

    return Table::make($livewire);
}
