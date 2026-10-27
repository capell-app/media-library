<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Tests\Fixtures;

use Capell\Tests\Fixtures\Models\User;
use Override;

/** Global site access without Shield's super-admin permission bypass. */
final class GlobalMediaHealthUser extends User
{
    protected $table = 'users';

    public function guardName(): string
    {
        return 'web';
    }

    #[Override]
    public function getMorphClass(): string
    {
        return (new User)->getMorphClass();
    }

    #[Override]
    public function isGlobalAdmin(): bool
    {
        return true;
    }
}
