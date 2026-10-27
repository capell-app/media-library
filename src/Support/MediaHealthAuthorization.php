<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Capell\Admin\Policies\Concerns\ResolvesShieldPermission;
use Capell\Admin\Support\SiteScope;
use Capell\MediaLibrary\Enums\MediaLibraryPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

final class MediaHealthAuthorization
{
    use ResolvesShieldPermission;

    public static function canView(?Authenticatable $actor): bool
    {
        return self::hasGlobalPermission($actor, MediaLibraryPermission::ViewMediaHealth->value);
    }

    public static function canDeleteOrphanMedia(?Authenticatable $actor): bool
    {
        return self::hasGlobalPermission($actor, MediaLibraryPermission::DeleteOrphanMedia->value);
    }

    public static function canEditMetadata(?Authenticatable $actor): bool
    {
        // Like health deletion, require the explicit permission rather than a
        // host super-admin Gate shortcut. Curator records are not Core Media.
        return self::canView($actor) && self::hasGlobalPermission($actor, self::permission('update', 'Media'));
    }

    public static function authorizeMetadataUpdate(?Authenticatable $actor): void
    {
        throw_unless(self::canEditMetadata($actor), AuthorizationException::class);
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorizeOrphanMediaDeletion(?Authenticatable $actor): void
    {
        throw_unless(self::canDeleteOrphanMedia($actor), AuthorizationException::class);
    }

    private static function hasGlobalPermission(?Authenticatable $actor, string $permission): bool
    {
        if (! $actor instanceof Authenticatable || ! SiteScope::isGlobalActor($actor) || ! method_exists($actor, 'checkPermissionTo')) {
            return false;
        }

        try {
            return $actor->checkPermissionTo($permission) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
