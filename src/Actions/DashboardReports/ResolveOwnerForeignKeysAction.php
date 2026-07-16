<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions\DashboardReports;

use Capell\MediaLibrary\Data\MediaOwnerForeignKeyData;
use Capell\MediaLibrary\Support\MediaUsageQueryExpressions;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<int, MediaOwnerForeignKeyData> run(array<int, array{table: string, column: string}>|null $ownerForeignKeys = null)
 */
final class ResolveOwnerForeignKeysAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     * @return array<int, MediaOwnerForeignKeyData>
     */
    public function handle(?array $ownerForeignKeys = null): array
    {
        return resolve(MediaUsageQueryExpressions::class)->knownOwnerForeignKeys(
            $this->configuredOrDiscoveredOwnerForeignKeys($ownerForeignKeys),
        );
    }

    /**
     * @param  array<int, array{table: string, column: string}>|null  $ownerForeignKeys
     */
    private function configuredOrDiscoveredOwnerForeignKeys(?array $ownerForeignKeys): mixed
    {
        if ($ownerForeignKeys !== null) {
            return $ownerForeignKeys;
        }

        $configuredOwnerForeignKeys = config('capell.media_library.owner_foreign_keys', []);

        if (is_array($configuredOwnerForeignKeys) && $configuredOwnerForeignKeys !== []) {
            return $configuredOwnerForeignKeys;
        }

        return DiscoverOwnerForeignKeysAction::run();
    }
}
