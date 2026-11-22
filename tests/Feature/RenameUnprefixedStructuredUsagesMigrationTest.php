<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

function runRenameStructuredUsagesMigration(): void
{
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_19_000001_rename_unprefixed_curator_structured_usages_table.php';
    $migration->up();
}

/** The table as the old migration built it, under the base name. */
function createLegacyStructuredUsagesTable(string $name = 'curator_structured_usages'): void
{
    Schema::dropIfExists($name);
    Schema::create($name, function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('media_id');
        $table->unsignedBigInteger('site_id')->nullable();
        $table->string('source_table', 64);
        $table->unsignedBigInteger('source_id');
        $table->timestamps();
        $table->unique(['source_table', 'source_id', 'media_id']);
    });
}

function seedStructuredUsage(string $table, int $mediaId): void
{
    DB::table($table)->insert(['media_id' => $mediaId, 'site_id' => 1, 'source_table' => 'layouts', 'source_id' => $mediaId, 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function (): void {
    Schema::dropIfExists('curator_structured_usages');
    Schema::dropIfExists('cp_curator_structured_usages');
});

it('moves the base-name table to the prefixed name with its rows and ids, and does nothing the second time', function (): void {
    config(['capell.database.table_prefix' => 'cp_']);
    createLegacyStructuredUsagesTable();
    seedStructuredUsage('curator_structured_usages', 7);
    seedStructuredUsage('curator_structured_usages', 9);

    runRenameStructuredUsagesMigration();

    expect(Schema::hasTable('curator_structured_usages'))->toBeFalse()
        ->and(Schema::hasTable('cp_curator_structured_usages'))->toBeTrue()
        ->and(DB::table('cp_curator_structured_usages')->orderBy('id')->pluck('media_id', 'id')->all())->toBe([1 => 7, 2 => 9]);

    runRenameStructuredUsagesMigration();

    expect(DB::table('cp_curator_structured_usages')->count())->toBe(2);
});

it('moves the table to the alias on an aliased install', function (): void {
    config(['capell.database.table_aliases' => ['curator_structured_usages' => 'media_usage']]);
    createLegacyStructuredUsagesTable();
    seedStructuredUsage('curator_structured_usages', 7);

    runRenameStructuredUsagesMigration();

    expect(Schema::hasTable('curator_structured_usages'))->toBeFalse()
        ->and(DB::table('media_usage')->pluck('media_id')->all())->toBe([7]);

    Schema::drop('media_usage');
});

it('leaves an unprefixed install untouched', function (): void {
    createLegacyStructuredUsagesTable();
    seedStructuredUsage('curator_structured_usages', 7);

    runRenameStructuredUsagesMigration();

    expect(DB::table('curator_structured_usages')->pluck('media_id')->all())->toBe([7]);
});

it('does nothing on a fresh prefixed install that has no base-name table', function (): void {
    config(['capell.database.table_prefix' => 'cp_']);
    createLegacyStructuredUsagesTable('cp_curator_structured_usages');
    seedStructuredUsage('cp_curator_structured_usages', 7);

    runRenameStructuredUsagesMigration();

    expect(Schema::hasTable('curator_structured_usages'))->toBeFalse()
        ->and(DB::table('cp_curator_structured_usages')->pluck('media_id')->all())->toBe([7]);
});

it('leaves both tables and their rows alone and logs it when both exist', function (): void {
    config(['capell.database.table_prefix' => 'cp_']);
    createLegacyStructuredUsagesTable();
    createLegacyStructuredUsagesTable('cp_curator_structured_usages');
    seedStructuredUsage('curator_structured_usages', 7);
    seedStructuredUsage('cp_curator_structured_usages', 8);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, '[curator_structured_usages] and [cp_curator_structured_usages]'));

    runRenameStructuredUsagesMigration();

    expect(DB::table('curator_structured_usages')->pluck('media_id')->all())->toBe([7])
        ->and(DB::table('cp_curator_structured_usages')->pluck('media_id')->all())->toBe([8]);
});
