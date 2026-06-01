<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Data;

use Spatie\LaravelData\Data;

final class MediaOwnerForeignKeyData extends Data
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
    ) {}
}
