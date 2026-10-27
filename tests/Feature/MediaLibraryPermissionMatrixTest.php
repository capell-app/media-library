<?php

declare(strict_types=1);

use Capell\Admin\Support\SiteScope;
use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Enums\MediaLibraryPermission;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\MediaLibrary\Filament\Pages\Tables\MediaHealthTable;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\GlobalMediaHealthUser;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    $configuredRole = config('capell.roles.super_admin', 'super_admin');
    Role::findOrCreate(is_string($configuredRole) ? $configuredRole : 'super_admin');

    foreach (MediaLibraryPermission::cases() as $permission) {
        Permission::findOrCreate($permission->value);
    }
});

it('matches media health access to the read permission', function (): void {
    $user = $this->createUserWithPermission(MediaLibraryPermission::ViewMediaHealth->value)->assignRole('super_admin');
    $this->actingAs($user);

    expect(MediaHealthPage::canAccess())->toBeTrue();
});

it('matches media health cleanup visibility to the destructive permission', function (bool $expectedVisible, array $permissionNames): void {
    $user = $this->createUserWithPermission($permissionNames)->assignRole('super_admin');
    $this->actingAs($user);

    $livewire = Livewire::test(MediaHealthPage::class)->assertOk();

    if ($expectedVisible) {
        $livewire->assertTableBulkActionVisible('delete_orphan_media');

        return;
    }

    $livewire->assertTableBulkActionHidden('delete_orphan_media');
})->with([
    'view-only' => [false, [MediaLibraryPermission::ViewMediaHealth->value]],
    'view-and-delete' => [true, [
        MediaLibraryPermission::ViewMediaHealth->value,
        MediaLibraryPermission::DeleteOrphanMedia->value,
    ]],
]);

it('blocks orphan cleanup without destructive permission', function (): void {
    $user = $this->createUserWithPermission(MediaLibraryPermission::ViewMediaHealth->value)->assignRole('super_admin');
    $this->actingAs($user);

    expect(fn (): int => DeleteOrphanMediaRecordsAction::run($user))->toThrow(AuthorizationException::class);
});

it('keeps a global health viewer read-only without media update permission', function (): void {
    Permission::findOrCreate('Update:Media');
    $user = GlobalMediaHealthUser::query()->create([
        'name' => 'Global health viewer', 'email' => 'health-viewer@example.test', 'password' => bcrypt('password'),
    ]);
    $user->givePermissionTo(MediaLibraryPermission::ViewMediaHealth->value);
    $this->actingAs($user);
    expect($user->hasRole('super_admin'))->toBeFalse()
        ->and(SiteScope::isGlobalActor($user))->toBeTrue()
        ->and($user->checkPermissionTo('Update:Media'))->toBeFalse()
        ->and(Gate::denies('Update:Media'))->toBeTrue();
    $id = DB::table('curator')->insertGetId([
        'disk' => 'public', 'directory' => 'media', 'visibility' => 'public',
        'name' => 'read-only-image', 'path' => 'media/read-only.jpg', 'type' => 'image/jpeg',
        'ext' => 'jpg', 'size' => 100, 'alt' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $record = CuratorMedia::query()->findOrFail($id);
    $component = Livewire::test(MediaHealthPage::class)->assertOk()->assertCanSeeTableRecords([$record]);
    $component->assertTableActionHidden('edit_metadata', $record);
    $component->call('mountAction', 'edit_metadata', [], ['table' => true, 'recordKey' => (string) $id])
        ->call('callMountedAction');
    expect($record->refresh()->alt)->toBeNull();
    expect(fn () => MediaHealthTable::applyMetadataEdit($record, ['alt' => 'Direct write']))
        ->toThrow(AuthorizationException::class);

    Permission::findOrCreate('Update:Media');
    $user->givePermissionTo('Update:Media');
    expect(Gate::allows('Update:Media'))->toBeTrue();
    Livewire::test(MediaHealthPage::class)->assertTableActionVisible('edit_metadata', $record)
        ->callTableAction('edit_metadata', $record, data: ['alt' => 'Approved description'])
        ->assertHasNoTableActionErrors();
    expect($record->refresh()->alt)->toBe('Approved description');

    $scopedActor = $this->createUserWithPermission([MediaLibraryPermission::ViewMediaHealth->value, 'Update:Media']);
    $this->actingAs($scopedActor);
    expect(SiteScope::isGlobalActor($scopedActor))->toBeFalse();
    Livewire::test(MediaHealthPage::class)->assertForbidden();
    expect(fn () => MediaHealthTable::applyMetadataEdit($record, ['alt' => 'Cross-site write']))
        ->toThrow(AuthorizationException::class);
    expect($record->refresh()->alt)->toBe('Approved description');
});
