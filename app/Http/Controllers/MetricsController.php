<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Exposes a Prometheus-compatible /metrics endpoint.
 * Course MGL842 — Course 07 (Observability / Metrics)
 *
 * Metrics exposed:
 *   - snipeit_info               : application version info
 *   - snipeit_assets_total       : total number of assets in the system
 *   - snipeit_users_total        : total number of users
 *   - snipeit_http_requests_total: HTTP request counter (from middleware)
 *   - snipeit_queue_size         : pending jobs in default queue
 *   - snipeit_up                 : always 1 (liveness probe for Prometheus)
 */
class MetricsController extends Controller
{
    public function __invoke(\Illuminate\Http\Request $request): Response
    {
        // Simple bearer token guard — set METRICS_TOKEN in .env
        // Leave METRICS_TOKEN empty to disable auth (internal network only)
        $token = config('app.metrics_token', env('METRICS_TOKEN', ''));
        if ($token !== '') {
            $provided = $request->bearerToken() ?? $request->query('token', '');
            if ($provided !== $token) {
                return response('Unauthorized', 401, [
                    'WWW-Authenticate' => 'Bearer realm="metrics"',
                ]);
            }
        }

        $lines = [];

        // ── Liveness ─────────────────────────────────────────────────────────
        $lines[] = '# HELP snipeit_up Whether the Snipe-IT application is running (1 = up)';
        $lines[] = '# TYPE snipeit_up gauge';
        $lines[] = 'snipeit_up 1';

        // ── Application info ─────────────────────────────────────────────────
        $version = config('version.app_version', 'unknown');
        $env     = config('app.env', 'unknown');
        $lines[] = '# HELP snipeit_info Static application metadata';
        $lines[] = '# TYPE snipeit_info gauge';
        $lines[] = "snipeit_info{version=\"{$version}\",env=\"{$env}\"} 1";

        // ── Business metrics (cached to avoid N+1 on every scrape) ───────────
        $assetCount = Cache::remember('metrics.assets_total', 60, function () {
            return DB::table('assets')->whereNull('deleted_at')->count();
        });

        $userCount = Cache::remember('metrics.users_total', 60, function () {
            return DB::table('users')->whereNull('deleted_at')->where('activated', 1)->count();
        });

        $deployedCount = Cache::remember('metrics.assets_deployed', 60, function () {
            return DB::table('assets')
                ->whereNull('deleted_at')
                ->whereNotNull('assigned_to')
                ->count();
        });

        $lines[] = '# HELP snipeit_assets_total Total number of non-deleted assets';
        $lines[] = '# TYPE snipeit_assets_total gauge';
        $lines[] = "snipeit_assets_total {$assetCount}";

        $lines[] = '# HELP snipeit_assets_deployed_total Number of assets currently deployed to users';
        $lines[] = '# TYPE snipeit_assets_deployed_total gauge';
        $lines[] = "snipeit_assets_deployed_total {$deployedCount}";

        $lines[] = '# HELP snipeit_users_total Total number of active users';
        $lines[] = '# TYPE snipeit_users_total gauge';
        $lines[] = "snipeit_users_total {$userCount}";

        // ── Queue depth ───────────────────────────────────────────────────────
        $queueSize = Cache::remember('metrics.queue_size', 15, function () {
            try {
                return DB::table('jobs')->count();
            } catch (\Throwable) {
                return 0;
            }
        });

        $lines[] = '# HELP snipeit_queue_size Number of pending jobs in the default queue';
        $lines[] = '# TYPE snipeit_queue_size gauge';
        $lines[] = "snipeit_queue_size {$queueSize}";

        // ── HTTP request metrics (populated by TraceRequestMiddleware) ────────
        $httpMetrics = Cache::get('metrics.http_requests', []);
        if (! empty($httpMetrics)) {
            $lines[] = '# HELP snipeit_http_requests_total Total HTTP requests by method and status';
            $lines[] = '# TYPE snipeit_http_requests_total counter';
            foreach ($httpMetrics as $labels => $count) {
                $lines[] = "snipeit_http_requests_total{{$labels}} {$count}";
            }
        }

        $durationMetrics = Cache::get('metrics.http_duration_sum', []);
        if (! empty($durationMetrics)) {
            $lines[] = '# HELP snipeit_http_request_duration_seconds_sum Sum of HTTP request durations';
            $lines[] = '# TYPE snipeit_http_request_duration_seconds_sum counter';
            foreach ($durationMetrics as $labels => $sum) {
                $lines[] = "snipeit_http_request_duration_seconds_sum{{$labels}} {$sum}";
            }
        }

        $output = implode("\n", $lines) . "\n";

        return response($output, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
