<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Enums;

enum MediaLibraryPermission: string
{
    case ViewMediaHealthPage = 'View:MediaHealthPage';
    case DeleteMediaHealthPage = 'Delete:MediaHealthPage';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
