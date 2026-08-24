<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\MediaLibrary\Data\MediaHealthIssuesData;
use Capell\MediaLibrary\Data\MediaHealthTotalsData;
use Capell\MediaLibrary\Models\CuratorMedia;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Counts how many media records carry each health issue.
 *
 * Totals overlap: a record with three issues counts once towards each of them,
 * which is what makes "fix the badge you can see" insufficient on its own.
 *
 * @method static MediaHealthTotalsData run(array<int, array{table: string, column: string}>|null $ownerForeignKeys = null, bool $useCache = true)
 */
final class BuildMediaHealthTotalsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     */
    public function handle(?array $ownerForeignKeys = null, bool $useCache = true): MediaHealthTotalsData
    {
        /** @var array<string, int> $issueTotals */
        $issueTotals = MediaHealthTotalsData::empty()->issueTotals;
        $recordTotal = 0;

        BuildMediaHealthQueryAction::run($ownerForeignKeys, $useCache)
            ->get()
            ->each(function (CuratorMedia $media) use (&$issueTotals, &$recordTotal): void {
                $recordTotal++;

                foreach (MediaHealthIssuesData::fromRecord($media)->issues as $issue) {
                    $issueTotals[$issue->value] = ($issueTotals[$issue->value] ?? 0) + 1;
                }
            });

        return new MediaHealthTotalsData($issueTotals, $recordTotal);
    }
}
