<?php

namespace App\Modules\Videos\Contracts;

/** A subtitle track, as the media pipeline needs it. */
final readonly class SubtitleTrack
{
    public function __construct(
        public string $id,
        public string $language,   // BCP 47
        public string $label,
        public string $kind,       // subtitles | captions
        public string $format,     // srt | vtt
        public string $sourceBucket,
        public string $sourceKey,
    ) {}
}
