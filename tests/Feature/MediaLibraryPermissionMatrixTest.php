<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\DashboardReports\DeleteOrphanMediaRecordsAction;
use Capell\MediaLibrary\Enums\MediaLibraryPermission;
use Capell\MediaLibrary\Filament\Pages\MediaHealthPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Role::findOrCreate(config('capell.roles.super_admin', 'super_admin'));

    foreach (MediaLibraryPermission::cases() as $permission) {
        Permission::findOrCreate($permission->value);
    }
});

it('matches media health access to the read permission', function (): void {
    $user = $this->createUserWithPermission(MediaLibraryPermission::ViewMediaHealth->value);
    $this->actingAs($user);

    expect(MediaHealthPage::canAccess())->toBeTrue();
});

it('matches media health cleanup visibility to the destructive permission', function (bool $expectedVisible, array $permissionNames): void {
    $user = $this->createUserWithPermission($permissionNames);
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
    $user = $this->createUserWithPermission(MediaLibraryPermission::ViewMediaHealth->value);
    $this->actingAs($user);

    expect(fn (): int => DeleteOrphanMediaRecordsAction::run())->toThrow(AuthorizationException::class);
});
