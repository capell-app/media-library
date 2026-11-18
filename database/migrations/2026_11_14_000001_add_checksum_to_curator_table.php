<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('curator') || Schema::hasColumn('curator', 'capell_checksum_sha256')) {
            return;
        }

        // The duplicates report groups on this column instead of re-reading every blob.
        Schema::table('curator', function (Blueprint $table): void {
            $table->string('capell_checksum_sha256', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        // The table belongs to the host's media library. Rolling back must retain stored checksums.
    }
};
