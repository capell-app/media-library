<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

final class MediaLibraryAuthorization
{
    public static function allows(string $permission): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if (self::userHasSuperAdminRole()) {
            return true;
        }

        if (Gate::allows($permission)) {
            return true;
        }

        return $user->can($permission) === true;
    }

    public static function authorize(string $permission): void
    {
        throw_unless(self::allows($permission), AuthorizationException::class);
    }

    private static function userHasSuperAdminRole(): bool
    {
        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'hasRole')) {
            return false;
        }

        $superAdminRole = config('capell.roles.super_admin', 'super_admin');

        return is_string($superAdminRole) && $superAdminRole !== '' && $user->hasRole($superAdminRole);
    }
}
