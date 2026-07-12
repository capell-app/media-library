<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;

final class CuratorMediaModelContribution implements ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
