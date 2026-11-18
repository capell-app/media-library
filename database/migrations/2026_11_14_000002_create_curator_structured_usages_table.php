<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('curator_structured_usages')) {
            return;
        }

        // Media referenced only from structured JSON, attributed to the site of the record holding the JSON. The
        // media picker narrows its list by site with this table, because the JSON cannot be matched in SQL.
        Schema::create('curator_structured_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            $table->timestamps();

            $table->unique(['source_table', 'source_id', 'media_id']);
            $table->index(['media_id', 'site_id']);
        });

        if (Schema::hasTable('curator') && array_key_exists('capell:media-rebuild-structured-usage', Artisan::all())) {
            Artisan::call('capell:media-rebuild-structured-usage');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('curator_structured_usages');
    }
};
