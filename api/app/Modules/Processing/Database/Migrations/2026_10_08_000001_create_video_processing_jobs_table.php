<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_processing_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_id');
            $table->uuid('upload_session_id');
            $table->unsignedInteger('processing_version');   // output goes to media/{video_id}/v{n}/
            $table->string('profile', 50);                   // encoding ladder (ADR-004)
            $table->string('status', 24)->default('queued');
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->string('source_bucket', 63);
            $table->string('source_key', 255);
            $table->string('output_bucket', 63);
            $table->string('output_prefix', 255);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_detail')->nullable();
            $table->timestampsTz();

            $table->foreign('video_id')->references('id')->on('videos')->restrictOnDelete();
            $table->foreign('upload_session_id')->references('id')->on('upload_sessions')->restrictOnDelete();
            $table->unique(['video_id', 'processing_version', 'profile']);
            $table->unique(['upload_session_id', 'profile']);   // one job per upload and ladder, however often VideoUploaded arrives
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE video_processing_jobs ADD CONSTRAINT video_processing_jobs_status_check
            CHECK (status IN ('queued','running','succeeded','partially_succeeded','failed','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('video_processing_jobs');
    }
};
