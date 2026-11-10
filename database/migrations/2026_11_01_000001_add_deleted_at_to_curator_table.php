<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('curator') || Schema::hasColumn('curator', 'deleted_at')) {
            return;
        }

        // Unused media is quarantined here before its blob is ever removed.
        Schema::table('curator', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // The table belongs to the host's media library. Rolling back must retain quarantined rows.
    }
};
