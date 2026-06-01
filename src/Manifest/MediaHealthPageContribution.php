<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;

final class MediaHealthPageContribution implements ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }
}
