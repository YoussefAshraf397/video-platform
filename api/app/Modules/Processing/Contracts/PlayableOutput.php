<?php

namespace App\Modules\Processing\Contracts;

/** The HLS output a video plays from: one processing version's prefix and master playlist. */
final readonly class PlayableOutput
{
    public function __construct(
        public int $processingVersion,
        public string $bucket,
        public string $prefix,      // media/{video_id}/v{n}/
        public string $masterKey,   // media/{video_id}/v{n}/master.m3u8
    ) {}
}
