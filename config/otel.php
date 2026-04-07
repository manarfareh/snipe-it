<?php

/**
 * OpenTelemetry configuration for Snipe-IT.
 * Course MGL842 — Course 07 (Distributed Tracing)
 */
return [

    /*
    |--------------------------------------------------------------------------
    | OTLP Exporter Endpoint
    |--------------------------------------------------------------------------
    | The URL of the OpenTelemetry collector or Jaeger OTLP HTTP receiver.
    | In docker-compose this is http://jaeger:4318/v1/traces
    */
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://jaeger:4318/v1/traces'),

    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    | Identifies this service in Jaeger. Shows up as the service name in traces.
    */
    'service_name' => env('OTEL_SERVICE_NAME', 'snipeit'),

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable Tracing
    |--------------------------------------------------------------------------
    | Set to false to completely disable tracing (e.g. in testing environments).
    */
    'enabled' => env('OTEL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Sampling Rate
    |--------------------------------------------------------------------------
    | Float between 0.0 (no traces) and 1.0 (all traces).
    | In production, start with 0.1 (10%) to reduce overhead.
    */
    'sampling_ratio' => env('OTEL_SAMPLING_RATIO', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Ignored Paths
    |--------------------------------------------------------------------------
    | These paths will not generate a trace span to reduce noise.
    */
    'ignored_paths' => [
        '/health',
        '/metrics',
        '/favicon.ico',
    ],

];
