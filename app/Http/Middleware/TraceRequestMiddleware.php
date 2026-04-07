<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpenTelemetry request tracing middleware.
 * Course MGL842 — Course 07 (Distributed Tracing)
 *
 * For each HTTP request this middleware:
 *   1. Creates an OTel span via the registered 'tracer' singleton
 *   2. Attaches HTTP semantic attributes (method, route, status, duration)
 *   3. Increments in-memory Prometheus counters stored in the cache
 *      (read back by MetricsController at /metrics)
 *   4. Propagates trace context via response headers for correlation
 */
class TraceRequestMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip ignored paths (health check, metrics itself, static assets)
        $ignoredPaths = config('otel.ignored_paths', ['/health', '/metrics']);
        foreach ($ignoredPaths as $path) {
            if ($request->is(ltrim($path, '/'))) {
                return $next($request);
            }
        }

        // Skip if tracing is disabled
        if (! config('otel.enabled', true)) {
            return $next($request);
        }

        $startTime = microtime(true);
        $traceId   = bin2hex(random_bytes(16));
        $spanId    = bin2hex(random_bytes(8));

        // Attempt to create a real OTel span (no-op if SDK not installed)
        $span   = null;
        $scope  = null;
        $tracer = null;

        try {
            $tracer = app('tracer');
            $span   = $tracer->spanBuilder($request->method() . ' ' . ($request->route()?->uri() ?? $request->path()))
                ->setAttribute('http.method', $request->method())
                ->setAttribute('http.url', $request->fullUrl())
                ->setAttribute('http.route', $request->route()?->uri() ?? 'unknown')
                ->setAttribute('http.user_agent', $request->userAgent() ?? '')
                ->setAttribute('net.peer.ip', $request->ip())
                ->startSpan();

            $scope = $span->activate();
        } catch (\Throwable $e) {
            Log::debug('OTel span creation failed: ' . $e->getMessage());
        }

        // Process the request
        $response = $next($request);

        $durationSeconds = microtime(true) - $startTime;
        $statusCode      = $response->getStatusCode();
        $method          = $request->method();
        $route           = $request->route()?->uri() ?? 'unknown';

        // Finalize the OTel span
        if ($span !== null) {
            try {
                $span->setAttribute('http.status_code', $statusCode)
                     ->setAttribute('http.response_content_length', strlen($response->getContent()))
                     ->setStatus(
                         $statusCode >= 500
                             ? \OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR
                             : \OpenTelemetry\API\Trace\StatusCode::STATUS_OK,
                         $statusCode >= 500 ? 'HTTP ' . $statusCode : ''
                     )
                     ->end();

                if ($scope !== null) {
                    $scope->detach();
                }
            } catch (\Throwable $e) {
                Log::debug('OTel span finalization failed: ' . $e->getMessage());
            }
        }

        // Increment Prometheus counters in cache (read by MetricsController)
        $this->recordHttpMetrics($method, $route, $statusCode, $durationSeconds);

        // Add trace ID to response headers for correlation with logs
        $response->headers->set('X-Trace-Id', $traceId);
        $response->headers->set('X-Span-Id', $spanId);

        return $response;
    }

    private function recordHttpMetrics(string $method, string $route, int $status, float $duration): void
    {
        try {
            $labelKey = "method=\"{$method}\",route=\"{$route}\",status=\"{$status}\"";

            // Increment request counter
            $counts = Cache::get('metrics.http_requests', []);
            $counts[$labelKey] = ($counts[$labelKey] ?? 0) + 1;
            Cache::put('metrics.http_requests', $counts, now()->addHours(1));

            // Accumulate duration sum
            $durations = Cache::get('metrics.http_duration_sum', []);
            $durationLabelKey = "method=\"{$method}\",route=\"{$route}\"";
            $durations[$durationLabelKey] = ($durations[$durationLabelKey] ?? 0.0) + $duration;
            Cache::put('metrics.http_duration_sum', $durations, now()->addHours(1));
        } catch (\Throwable) {
            // Never let metrics recording break the request
        }
    }
}
