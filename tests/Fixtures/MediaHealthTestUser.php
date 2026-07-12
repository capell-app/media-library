<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class MediaHealthTestUser extends User
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        private readonly bool $global = false,
        private readonly array $permissions = [],
    ) {
        parent::__construct();
    }

    public function isGlobalAdmin(): bool
    {
        return $this->global;
    }

    public function checkPermissionTo(mixed $permission, mixed $guardName = null): bool
    {
        unset($guardName);

        return is_scalar($permission)
            && in_array((string) $permission, $this->permissions, true);
    }
}
