<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions;

use Capell\MediaLibrary\Actions\DashboardReports\BuildMissingAltMediaQueryAction;
use Capell\MediaLibrary\Events\MediaMissingAltDetected;
use Capell\MediaLibrary\Models\CuratorMedia;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DispatchMissingAltMediaSignalsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     */
    public function handle(?array $ownerForeignKeys = null, ?int $limit = null, bool $onlyImages = true): int
    {
        $query = BuildMissingAltMediaQueryAction::run($ownerForeignKeys, $onlyImages);

        if ($limit !== null) {
            $query->limit(max(0, $limit));
        }

        $dispatched = 0;

        $query->get()->each(function (CuratorMedia $media) use (&$dispatched): void {
            event(new MediaMissingAltDetected(
                media: $media,
                usageCount: $this->intValue($media->getAttribute('usage_count')),
            ));

            $dispatched++;
        });

        return $dispatched;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
