<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;

final class SiteScopedMediaUser extends User
{
    /**
     * @param  list<int>  $siteIds
     */
    public function __construct(private readonly array $siteIds = [], private readonly bool $global = false)
    {
        parent::__construct();
    }

    public function isGlobalAdmin(): bool
    {
        return $this->global;
    }

    /**
     * @return Collection<int, int>
     */
    public function getAssignedSiteIds(): Collection
    {
        return collect($this->siteIds);
    }

    public function checkPermissionTo(mixed $permission, mixed $guardName = null): bool
    {
        unset($permission, $guardName);

        return true;
    }
}
