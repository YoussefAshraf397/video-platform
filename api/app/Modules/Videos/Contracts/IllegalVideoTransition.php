<?php

namespace App\Modules\Videos\Contracts;

use RuntimeException;

/**
 * The video isn't in a state that allows the requested transition, for example because another
 * request or message got there first. Nothing was changed and no event was published.
 */
final class IllegalVideoTransition extends RuntimeException
{
    public function __construct(
        public readonly string $videoId,
        public readonly VideoStatus $from,
        public readonly VideoStatus $to,
    ) {
        parent::__construct("Video {$videoId} cannot go from {$from->value} to {$to->value}");
    }
}
