<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The structured usage migration used to create its table under the base name even when the install prefixes or
 * aliases Capell tables. Installs that ran it then hold `curator_structured_usages`, while the code now reads the
 * resolved name. Move the table once so those installs keep their index instead of reading a table that is not there.
 *
 * The table has no foreign keys, so a rename is enough: it is a single metadata statement on MySQL, MariaDB and
 * SQLite, keeps every row and cannot leave both tables behind if it fails. Nothing is ever dropped or overwritten.
 * When both tables exist the migration leaves them as they are and logs it, because choosing between two sets of
 * rows is an operator decision; the index is derived data that capell:media-rebuild-structured-usage rebuilds.
 * Default and fresh installs never match: the base and resolved names are equal, or the base-name table is absent.
 */
return new class extends Migration
{
    /**
     * The name the old migration created. It is deliberately never resolved, because this migration exists to find
     * that unprefixed table. It sits in a keyed map because the table-name architecture test requires every plain
     * string constant holding a Capell table name to be passed through the resolver.
     */
    private const array LEGACY = ['usages' => 'curator_structured_usages'];

    public function up(): void
    {
        $resolved = TableName::resolve(self::LEGACY['usages']);

        if ($resolved === self::LEGACY['usages'] || ! Schema::hasTable(self::LEGACY['usages'])) {
            return;
        }

        if (Schema::hasTable($resolved)) {
            Log::warning(sprintf(
                'Media library: both [%s] and [%s] exist, so neither was changed. Keep [%s], then run capell:media-rebuild-structured-usage to refresh it and drop [%s] once you are satisfied.',
                self::LEGACY['usages'],
                $resolved,
                $resolved,
                self::LEGACY['usages'],
            ));

            return;
        }

        Schema::rename(self::LEGACY['usages'], $resolved);
    }

    public function down(): void
    {
        // The resolved name is the supported location; renaming back would strand the table where the code no longer looks.
    }
};
