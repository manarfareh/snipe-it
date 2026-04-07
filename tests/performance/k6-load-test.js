/**
 * Snipe-IT API Load Test — Course MGL842 (Course 07)
 *
 * Usage:
 *   k6 run tests/performance/k6-load-test.js
 *   k6 run -e BASE_URL=http://localhost:8000 -e API_TOKEN=your_token tests/performance/k6-load-test.js
 *
 * SLOs being validated:
 *   - p95 response time < 2000ms
 *   - Error rate < 5%
 *   - Availability > 99.5%
 */

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// ── Custom Metrics ────────────────────────────────────────────────────────────
const errorRate    = new Rate('error_rate');
const apiLatency   = new Trend('api_latency_ms', true);
const requestCount = new Counter('total_requests');

// ── Test Configuration ────────────────────────────────────────────────────────
export const options = {
  // Load stages: ramp up → sustained → stress → ramp down
  stages: [
    { duration: '30s', target: 5  },   // Warm-up
    { duration: '1m',  target: 20 },   // Normal load
    { duration: '1m',  target: 50 },   // Sustained load
    { duration: '30s', target: 80 },   // Stress test
    { duration: '30s', target: 0  },   // Ramp down
  ],

  // SLO thresholds — test FAILS if any are breached
  thresholds: {
    // SLO 1: 95th percentile latency < 2 seconds
    http_req_duration: ['p(95)<2000', 'p(99)<5000'],
    // SLO 2: Error rate < 5%
    error_rate: ['rate<0.05'],
    // All checks must pass at least 99% of the time
    checks: ['rate>0.99'],
  },
};

// ── Environment Variables ─────────────────────────────────────────────────────
const BASE_URL  = __ENV.BASE_URL  || 'http://localhost:8000';
const API_TOKEN = __ENV.API_TOKEN || '';

const authHeaders = {
  headers: {
    Authorization: `Bearer ${API_TOKEN}`,
    Accept:        'application/json',
    'Content-Type': 'application/json',
  },
};

// ── Helper: record metrics ────────────────────────────────────────────────────
function record(res) {
  requestCount.add(1);
  apiLatency.add(res.timings.duration);
  errorRate.add(res.status >= 400);
}

// ── Main Virtual User Scenario ────────────────────────────────────────────────
export default function () {

  // ── Group 1: Health & Public endpoints ──────────────────────────────────
  group('Health Check', function () {
    const res = http.get(`${BASE_URL}/health`);
    record(res);
    check(res, {
      'health endpoint returns 200': (r) => r.status === 200,
      'health response time < 500ms': (r) => r.timings.duration < 500,
    });
  });

  sleep(0.5);

  // ── Group 2: API — Assets ────────────────────────────────────────────────
  group('Assets API', function () {

    // List assets
    const listRes = http.get(`${BASE_URL}/api/v1/hardware?limit=50&offset=0`, authHeaders);
    record(listRes);
    check(listRes, {
      'list assets: status 200': (r) => r.status === 200,
      'list assets: has rows array': (r) => {
        try { return JSON.parse(r.body).rows !== undefined; } catch { return false; }
      },
      'list assets: response < 2000ms': (r) => r.timings.duration < 2000,
    });

    sleep(0.5);

    // Single asset lookup (use ID 1 as a smoke test)
    const singleRes = http.get(`${BASE_URL}/api/v1/hardware/1`, authHeaders);
    record(singleRes);
    check(singleRes, {
      'get asset: not 500': (r) => r.status !== 500,
      'get asset: response < 1000ms': (r) => r.timings.duration < 1000,
    });
  });

  sleep(0.5);

  // ── Group 3: API — Users ─────────────────────────────────────────────────
  group('Users API', function () {
    const res = http.get(`${BASE_URL}/api/v1/users?limit=25`, authHeaders);
    record(res);
    check(res, {
      'list users: status 200': (r) => r.status === 200,
      'list users: response < 2000ms': (r) => r.timings.duration < 2000,
    });
  });

  sleep(0.5);

  // ── Group 4: API — Categories ────────────────────────────────────────────
  group('Categories API', function () {
    const res = http.get(`${BASE_URL}/api/v1/categories`, authHeaders);
    record(res);
    check(res, {
      'list categories: status 200': (r) => r.status === 200,
      'list categories: response < 1000ms': (r) => r.timings.duration < 1000,
    });
  });

  sleep(1);
}

// ── Lifecycle: Setup ──────────────────────────────────────────────────────────
export function setup() {
  console.log(`Target: ${BASE_URL}`);
  console.log(`Token present: ${API_TOKEN !== ''}`);

  const res = http.get(`${BASE_URL}/health`);
  if (res.status !== 200) {
    throw new Error(`App is not healthy before test (status ${res.status}). Aborting.`);
  }
  return { baseUrl: BASE_URL };
}

// ── Lifecycle: Teardown ───────────────────────────────────────────────────────
export function teardown(data) {
  console.log(`Test completed against: ${data.baseUrl}`);
}

// ── Summary Output ────────────────────────────────────────────────────────────
export function handleSummary(data) {
  const p95 = data.metrics.http_req_duration?.values?.['p(95)'] ?? 'N/A';
  const errRate = data.metrics.error_rate?.values?.rate ?? 'N/A';
  const total  = data.metrics.total_requests?.values?.count ?? 'N/A';

  console.log('\n=== MGL842 SLO Summary ===');
  console.log(`Total requests:   ${total}`);
  console.log(`p95 latency:      ${typeof p95 === 'number' ? p95.toFixed(0) + 'ms' : p95}  (SLO: < 2000ms)`);
  console.log(`Error rate:       ${typeof errRate === 'number' ? (errRate * 100).toFixed(2) + '%' : errRate}  (SLO: < 5%)`);
  console.log('=========================\n');

  return {
    'tests/performance/k6-results.json': JSON.stringify(data, null, 2),
    stdout: '\nSee k6-results.json for full results\n',
  };
}
