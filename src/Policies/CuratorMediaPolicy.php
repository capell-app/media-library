<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Policies;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaSiteAccess;
use Illuminate\Foundation\Auth\User;

/**
 * Curator library rows have no owning record. Access follows the same Media permissions as Core's MediaPolicy, and a
 * site-restricted administrator additionally cannot act on media used only by other sites (see
 * CuratorMediaSiteAccess).
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
        return $this->viewAny($user) && CuratorMediaSiteAccess::canUse($user, $media);
    }

    public function create(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('create', self::SUBJECT));
    }

    public function update(User $user, CuratorMedia $media): bool
    {
        return $user->checkPermissionTo(self::permission('update', self::SUBJECT))
            && CuratorMediaSiteAccess::canUse($user, $media);
    }

    public function delete(User $user, CuratorMedia $media): bool
    {
        return $user->checkPermissionTo(self::permission('delete', self::SUBJECT))
            && CuratorMediaSiteAccess::canUse($user, $media);
    }

    public function deleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('delete_any', self::SUBJECT));
    }

    public function restore(User $user, CuratorMedia $media): bool
    {
        return $user->checkPermissionTo(self::permission('restore', self::SUBJECT))
            && CuratorMediaSiteAccess::canUse($user, $media);
    }

    public function restoreAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('restore_any', self::SUBJECT));
    }

    public function forceDelete(User $user, CuratorMedia $media): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete', self::SUBJECT))
            && CuratorMediaSiteAccess::canUse($user, $media);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->checkPermissionTo(self::permission('force_delete_any', self::SUBJECT));
    }
}
