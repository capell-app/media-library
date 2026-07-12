<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Data;

use Spatie\LaravelData\Data;

final class MediaUsageReferenceData extends Data
{
    public function __construct(
        public readonly string $table,
        public readonly string $column,
        public readonly string $recordId,
        public readonly ?string $label,
    ) {}
}
