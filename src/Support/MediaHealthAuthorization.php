<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\Admin\Support\SiteScope;
use Capell\MediaLibrary\Enums\MediaLibraryPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

final class MediaHealthAuthorization
{
    public static function canView(?Authenticatable $actor): bool
    {
        return self::hasGlobalPermission($actor, MediaLibraryPermission::ViewMediaHealth);
    }

    public static function canDeleteOrphanMedia(?Authenticatable $actor): bool
    {
        return self::hasGlobalPermission($actor, MediaLibraryPermission::DeleteOrphanMedia);
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorizeOrphanMediaDeletion(?Authenticatable $actor): void
    {
        throw_unless(self::canDeleteOrphanMedia($actor), AuthorizationException::class);
    }

    private static function hasGlobalPermission(?Authenticatable $actor, MediaLibraryPermission $permission): bool
    {
        if (! $actor instanceof Authenticatable || ! SiteScope::isGlobalActor($actor) || ! method_exists($actor, 'checkPermissionTo')) {
            return false;
        }

        try {
            return $actor->checkPermissionTo($permission->value) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
