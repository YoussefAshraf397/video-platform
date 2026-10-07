<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_processing_jobs', function (Blueprint $table) {
            $table->string('master_playlist_key', 255)->nullable();   // set by the first VideoRenditionReady
            $table->string('failed_step', 32)->nullable();
            $table->boolean('failure_retryable')->nullable();
        });

        // Every playable rung of every job (design doc §9 video_variants).
        Schema::create('video_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_id');
            $table->uuid('processing_job_id');
            $table->unsignedInteger('processing_version');
            $table->string('codec', 16);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->decimal('frame_rate', 6, 3);
            $table->unsignedInteger('bitrate_avg');
            $table->unsignedInteger('bitrate_peak');
            $table->string('playlist_key', 255);
            $table->unsignedInteger('segment_duration_ms');
            $table->string('status', 16)->default('ready');
            $table->timestampTz('created_at');

            $table->foreign('video_id')->references('id')->on('videos')->restrictOnDelete();
            $table->foreign('processing_job_id')->references('id')->on('video_processing_jobs')->cascadeOnDelete();
            $table->unique(['processing_job_id', 'playlist_key']);   // a redelivered result is an upsert
            $table->index(['video_id', 'status']);
        });

        // Every stored file group of a video, so the purge saga knows what to delete (design doc §9 video_assets).
        Schema::create('video_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_id');
            $table->string('asset_type', 24);   // source | hls (renditions, master and thumbnails under one prefix)
            $table->string('bucket', 63);
            $table->string('key_prefix', 255);
            $table->unsignedInteger('processing_version')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('video_id')->references('id')->on('videos')->restrictOnDelete();
            $table->unique(['bucket', 'key_prefix']);
            $table->index(['video_id', 'asset_type']);
        });
        DB::statement("ALTER TABLE video_assets ADD CONSTRAINT video_assets_type_check CHECK (asset_type IN ('source','hls'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('video_assets');
        Schema::dropIfExists('video_variants');
        Schema::table('video_processing_jobs', function (Blueprint $table) {
            $table->dropColumn(['master_playlist_key', 'failed_step', 'failure_retryable']);
        });
    }
};
