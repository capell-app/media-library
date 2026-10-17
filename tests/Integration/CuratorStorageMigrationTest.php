<?php

declare(strict_types=1);

use Awcodes\Curator\Support\MediaScope;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\TestCuratorOwner;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('creates usable Curator storage through the package migrations on a fresh install', function (): void {
    expect(Schema::hasTable((new CuratorMedia)->getTable()))->toBeTrue();
    expect(CuratorMedia::query()->count())->toBe(0)
        ->and(Schema::hasColumn('curator', 'tenant_id'))->toBeTrue();
});

it('lists and assigns uploaded media to the configured tenant relationship on a fresh install', function (): void {
    config()->set('curator.features.tenancy', ['enabled' => true, 'relationship_name' => 'team']);
    Schema::drop('curator');
    $migration = require __DIR__ . '/../../database/migrations/2026_10_15_000001_create_curator_table.php';
    throw_unless($migration instanceof Migration && method_exists($migration, 'up'), RuntimeException::class);
    $migration->up();

    $tenant = TestCuratorOwner::query()->create(['name' => 'Current team']);
    $otherTenant = TestCuratorOwner::query()->create(['name' => 'Other team']);
    $previousPanel = Filament::getCurrentPanel();
    $previousTenant = Filament::getTenant();
    Filament::setCurrentPanel(Panel::make()->id('media-tenancy')->tenant(TestCuratorOwner::class, ownershipRelationship: 'team'));
    Filament::setTenant($tenant, isQuiet: true);

    try {
        $scope = new MediaScope(disk: 'public', isTenantAware: true, tenantOwnershipRelationshipName: 'team');
        expect($scope->query()->count())->toBe(0)
            ->and(Schema::hasColumn('curator', 'team_id'))->toBeTrue()
            ->and(Schema::hasColumn('curator', 'tenant_id'))->toBeFalse();

        Livewire::test('curator-panel', ['settings' => [
            'diskName' => 'public',
            'isTenantAware' => true,
            'tenantOwnershipRelationshipName' => 'team',
            'acceptedFileTypes' => ['image/jpeg'],
            'shouldPreserveFilenames' => true,
        ]])
            ->set('panelData.files_to_add', [UploadedFile::fake()->image('team-upload.jpg')])
            ->callAction('addFiles')
            ->assertHasNoErrors();

        $media = CuratorMedia::query()->sole();
        expect($media->getAttribute('team_id'))->toBe($tenant->getKey());
        Storage::disk('public')->assertExists($media->path);

        DB::table('curator')->insert([
            'disk' => 'public',
            'name' => 'other-team-image',
            'path' => 'other-team-image.jpg',
            'type' => 'image/jpeg',
            'ext' => 'jpg',
            'team_id' => $otherTenant->getKey(),
        ]);

        expect($scope->query()->pluck('id')->all())->toBe([$media->getKey()]);
        Livewire::test('curator-panel', ['settings' => [
            'diskName' => 'public',
            'isTenantAware' => true,
            'tenantOwnershipRelationshipName' => 'team',
        ]])
            ->assertSee('team-upload')
            ->assertDontSee('other-team-image')
            ->assertSet('files.0.id', $media->getKey())
            ->assertSet('mediaCount', 1);
    } finally {
        Filament::setTenant($previousTenant, isQuiet: true);
        Filament::setCurrentPanel($previousPanel);
    }
});

it('preserves existing Curator records and schema when the upgrade migration runs again', function (): void {
    config()->set('curator.features.tenancy', ['enabled' => true, 'relationship_name' => 'team']);
    DB::table('curator')->insert([
        'id' => 41,
        'disk' => 'public',
        'directory' => 'legacy',
        'name' => 'Existing image',
        'path' => 'legacy/image.jpg',
        'type' => 'image/jpeg',
        'ext' => 'jpg',
        'alt' => 'Existing alternative text',
        'curations' => '[{"curation":{"key":"legacy-crop"}}]',
    ]);
    $columns = Schema::getColumns('curator');
    $record = DB::table('curator')->where('id', 41)->first();
    $migration = require __DIR__ . '/../../database/migrations/2026_10_15_000001_create_curator_table.php';
    throw_unless($migration instanceof Migration && method_exists($migration, 'up') && method_exists($migration, 'down'), RuntimeException::class);

    $migration->up();
    $migration->up();

    expect(Schema::getColumns('curator'))->toBe($columns)
        ->and(DB::table('curator')->where('id', 41)->first())->toEqual($record)
        ->and(CuratorMedia::query()->count())->toBe(1);
});

it('adopts a pre-existing UUID Curator table without altering it or deleting it on rollback', function (): void {
    Schema::drop('curator');
    Schema::create('curator', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('path');
        $table->string('host_custom_column');
    });
    DB::table('curator')->insert([
        'id' => 'a2d123b1-9aa1-497a-a864-b3a189111321',
        'path' => 'legacy/existing.jpg',
        'host_custom_column' => 'preserve me',
    ]);
    $columns = Schema::getColumns('curator');
    $records = DB::table('curator')->get()->all();
    $migration = require __DIR__ . '/../../database/migrations/2026_10_15_000001_create_curator_table.php';
    throw_unless($migration instanceof Migration && method_exists($migration, 'up') && method_exists($migration, 'down'), RuntimeException::class);

    $migration->up();
    $migration->up();
    $migration->down();

    expect(Schema::getColumns('curator'))->toBe($columns)
        ->and(DB::table('curator')->get()->all())->toEqual($records);
});
