<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support\Database;

use Illuminate\Database\Connection;

/**
 * Owned database compatibility seam: the one place Media Library chooses a
 * driver-specific cast so structured (JSON or text) columns can be searched
 * with LIKE on every supported database.
 */
final class StructuredColumnTextCast
{
    public function expression(Connection $connection, string $wrappedColumn): string
    {
        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'cast(' . $wrappedColumn . ' as char)',
            'sqlsrv' => 'cast(' . $wrappedColumn . ' as nvarchar(max))',
            default => 'cast(' . $wrappedColumn . ' as text)',
        };
    }
}
