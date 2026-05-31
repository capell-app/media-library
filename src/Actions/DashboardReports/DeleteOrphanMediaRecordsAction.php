<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Lorisleiva\Actions\Concerns\AsAction;

final class DeleteOrphanMediaRecordsAction
{
    use AsAction;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     */
    public function handle(?array $ownerForeignKeys = null, int $limit = 100): int
    {
        $limit = max(1, min($limit, 1000));
        $ids = BuildOrphanMediaQueryAction::run($ownerForeignKeys)
            ->limit($limit)
            ->pluck('curator.id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return BuildOrphanMediaQueryAction::run($ownerForeignKeys)
            ->whereIn('curator.id', $ids)
            ->delete();
    }
}
