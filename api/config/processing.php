<?php

/* Media processing (design doc §11). */
return [
    // Where the worker writes renditions, manifests and thumbnails: media/{video_id}/v{n}/.
    'output_bucket' => env('MEDIA_BUCKET', 'media'),
];
