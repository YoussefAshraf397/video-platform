<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uploaded subtitle tracks (design doc §7 "subtitles", S4-06). The converted WebVTT lives
        // with each processing version's outputs; this table is the creator's view of the tracks.
        Schema::create('subtitles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_id');
            $table->foreign('video_id')->references('id')->on('videos')->cascadeOnDelete();
            $table->string('language', 35);                 // BCP 47, normalized (en, pt-BR, zh-Hant)
            $table->string('label', 80);
            $table->string('kind', 16);                     // subtitles | captions (SDH)
            $table->string('format', 4);                    // srt | vtt, as uploaded
            $table->integer('size_bytes');
            $table->string('source_bucket', 63);
            $table->string('source_key', 255);
            $table->string('status', 24);
            $table->string('error_code', 64)->nullable();
            $table->string('error_detail', 1000)->nullable();
            $table->timestampTz('upload_expires_at');
            $table->timestampsTz();
            $table->index(['video_id', 'created_at']);
        });
        DB::statement("ALTER TABLE subtitles ADD CONSTRAINT subtitles_kind_check CHECK (kind IN ('subtitles', 'captions'))");
        DB::statement("ALTER TABLE subtitles ADD CONSTRAINT subtitles_format_check CHECK (format IN ('srt', 'vtt'))");
        DB::statement("ALTER TABLE subtitles ADD CONSTRAINT subtitles_status_check CHECK (status IN ('awaiting_upload', 'processing', 'ready', 'failed', 'deleted'))");
        // One live track per language and kind (design doc: UQ video_id, language, kind).
        DB::statement("CREATE UNIQUE INDEX subtitles_one_live_per_language ON subtitles (video_id, language, kind) WHERE status IN ('awaiting_upload', 'processing', 'ready')");
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitles');
    }
};
