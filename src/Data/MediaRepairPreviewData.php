<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Data;

final readonly class MediaRepairPreviewData
{
    public function __construct(
        public ?string $url = null,
    ) {}
}
