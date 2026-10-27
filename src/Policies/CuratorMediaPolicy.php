<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Foundation\Auth\User;

/**
 * Curator library rows are shared across sites and have no owning record, so
 * access follows the same Media permissions as Core's MediaPolicy without its
 * owner-based site check.
 */
final class CuratorMediaPolicy
{
    use ResolvesShieldPermission;

    private const string SUBJECT = 'Media';

    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('view_any', self::SUBJECT))
            || $user->checkPermissionTo(self::permission('view', self::SUBJECT));
    }

    public function view(User $user, CuratorMedia $media): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('create', self::SUBJECT));
    }

    public function update(User $user, CuratorMedia $media): bool
    {
        return $user->checkPermissionTo(self::permission('update', self::SUBJECT));
    }

    public function delete(User $user, CuratorMedia $media): bool
    {
        return $user->checkPermissionTo(self::permission('delete', self::SUBJECT));
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', self::SUBJECT));
    }

    public function restore(User $user, CuratorMedia $media): bool
    {
        return $user->checkPermissionTo(self::permission('restore', self::SUBJECT));
    }

    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('restore_any', self::SUBJECT));
    }

    public function forceDelete(User $user, CuratorMedia $media): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete', self::SUBJECT));
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete_any', self::SUBJECT));
    }
}
