<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing hosts may already have run curator:install, including with UUID keys.
        if (Schema::hasTable('curator')) {
            return;
        }

        Schema::create('curator', function (Blueprint $table): void {
            $table->id();
            $table->string('disk');
            $table->string('directory')->nullable();
            $table->string('visibility')->default('public');
            $table->string('name');
            $table->string('path')->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('type');
            $table->string('ext');
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('caption')->nullable();
            $table->text('pretty_name')->nullable();
            $table->text('exif')->nullable();
            $table->longText('curations')->nullable();
            $tenantRelationship = config('curator.features.tenancy.relationship_name');
            $tenantRelationship = is_string($tenantRelationship) && $tenantRelationship !== '' ? $tenantRelationship : 'tenant';
            $table->unsignedBigInteger($tenantRelationship . '_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // The table may predate this migration. Rolling back must retain the host's media.
    }
};
