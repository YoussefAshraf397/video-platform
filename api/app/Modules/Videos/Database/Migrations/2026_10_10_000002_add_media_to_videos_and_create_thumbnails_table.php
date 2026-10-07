<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // What processing found in the source (display size, after rotation).
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('source_width')->nullable();
            $table->unsignedSmallInteger('source_height')->nullable();
            $table->unsignedInteger('processing_version')->nullable();   // the version whose media is recorded
        });

        // One row per thumbnail candidate; `files` holds its sizes and formats.
        Schema::create('thumbnails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_id');
            $table->unsignedInteger('processing_version');
            $table->string('source', 16)->default('auto');   // auto | custom (custom upload arrives later)
            $table->unsignedInteger('time_offset_ms')->nullable();
            $table->jsonb('files');                           // [{key, width, height, format}]
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('created_at');

            $table->foreign('video_id')->references('id')->on('videos')->cascadeOnDelete();
            $table->unique(['video_id', 'processing_version', 'source', 'time_offset_ms']);
        });
        DB::statement('CREATE UNIQUE INDEX thumbnails_one_primary_per_video ON thumbnails (video_id) WHERE is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('thumbnails');
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['duration_ms', 'source_width', 'source_height', 'processing_version']);
        });
    }
};
