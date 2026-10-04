<?php

/* Upload module limits (design doc §10, ADR-003). */
return [
    'bucket' => env('UPLOADS_BUCKET', 'uploads'),

    'max_size_bytes' => (int) env('UPLOADS_MAX_SIZE_BYTES', 10 * 1024 ** 3),   // 10 GiB at the MVP

    // Declared by the client and only a first filter: the worker sniffs and probes the real bytes (§10.6).
    'content_types' => ['video/mp4', 'video/quicktime', 'video/x-matroska', 'video/webm', 'video/x-msvideo', 'video/mpeg', 'video/3gpp'],

    // Upload sessions a user may start in any rolling 24 hours.
    'daily_session_quota' => (int) env('UPLOADS_DAILY_SESSION_QUOTA', 25),

    'session_ttl_seconds' => 24 * 3600,   // slides forward on every sign/status call
    'url_ttl_seconds' => 30 * 60,
    'url_batch_size' => 20,               // URLs handed out with a create or status response
    'max_sign_batch' => 100,              // part numbers per parts:sign request
];
