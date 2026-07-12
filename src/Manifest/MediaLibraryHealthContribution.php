<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Manifest;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;

final class MediaLibraryHealthContribution implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }
}
