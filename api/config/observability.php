<?php

/*
 * OpenTelemetry tracing (App\Platform\Observability). Trace context crosses process boundaries as a
 * W3C `traceparent`: HTTP headers in, SNS/SQS message attributes between services.
 */
return [
    'service_name' => env('OTEL_SERVICE_NAME', 'api'),

    // The deployed build (CD sets the git SHA), so traces show which version handled a request.
    'service_version' => env('APP_VERSION', 'dev'),

    // otlp: OTLP/HTTP to OTEL_EXPORTER_OTLP_ENDPOINT (the ADOT collector sidecar in AWS).
    // console: JSON spans on stderr (local debugging). none: spans exist (IDs in logs) but go nowhere.
    'exporter' => env('OTEL_TRACES_EXPORTER', env('OTEL_EXPORTER_OTLP_ENDPOINT') ? 'otlp' : 'none'),
];
