<?php

namespace App\Modules\Processing\Contracts;

/** The HLS output a video plays from: one processing version's prefix and master playlist. */
final readonly class PlayableOutput
{
    /**
     * @param  list<array{subtitle_id: string, language: string, label: string, kind: string, playlist_key: string, vtt_key: string}>  $captions  the subtitle tracks the master lists
     */
    public function __construct(
        public int $processingVersion,
        public string $bucket,
        public string $prefix,      // media/{video_id}/v{n}/
        public string $masterKey,   // media/{video_id}/v{n}/master.m3u8, or master.c{k}.m3u8 once captions are published
        public array $captions = [],
    ) {}
}
