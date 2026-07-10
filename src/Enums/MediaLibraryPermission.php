<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Enums;

enum MediaLibraryPermission: string
{
    case ViewMediaHealth = 'View:MediaHealthPage';
    case DeleteOrphanMedia = 'Delete:MediaHealthPage';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
