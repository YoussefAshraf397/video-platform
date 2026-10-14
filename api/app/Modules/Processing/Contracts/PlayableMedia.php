<?php

namespace App\Modules\Processing\Contracts;

interface PlayableMedia
{
    /** The newest processing version with at least one playable rendition; null if there is none yet. */
    public function current(string $videoId): ?PlayableOutput;
}
