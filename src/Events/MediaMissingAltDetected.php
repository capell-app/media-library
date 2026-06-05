<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Events;

use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class MediaMissingAltDetected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public CuratorMedia $media,
        public int $usageCount = 0,
    ) {}
}
