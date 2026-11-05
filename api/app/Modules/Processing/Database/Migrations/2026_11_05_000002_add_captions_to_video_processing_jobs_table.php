<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_processing_jobs', function (Blueprint $table) {
            // Captions revisions of this version (S4-06): each MediaCaptionsRequested gets the next
            // number; the worker writes master.c{n}.m3u8 for it. Only a newer published revision
            // replaces the current one, so results arriving out of order can't go backwards.
            $table->integer('captions_revision')->default(0);
            $table->integer('captions_published_revision')->default(0);
            $table->string('captions_master_key', 255)->nullable();   // null: play the base master
            $table->jsonb('captions')->nullable();                    // the published revision's tracks
        });
    }

    public function down(): void
    {
        Schema::table('video_processing_jobs', function (Blueprint $table) {
            $table->dropColumn(['captions_revision', 'captions_published_revision', 'captions_master_key', 'captions']);
        });
    }
};
