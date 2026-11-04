// k6 load test — platform HTTP baseline.
// Run:  docker run --rm --network yep_frontend -v "$PWD/tests/Performance/k6:/scripts" grafana/k6:0.54.0 run /scripts/smoke.js
// Env:  BASE_URL (default http://nginx:8080), VUS, DURATION, API_TOKEN (optional, enables authenticated API scenario)
import http from 'k6/http';
import { check, group } from 'k6';

const BASE = __ENV.BASE_URL || 'http://nginx:8080';
const TOKEN = __ENV.API_TOKEN || '';

export const options = {
  scenarios: {
    web: { executor: 'constant-vus', vus: Number(__ENV.VUS || 20), duration: __ENV.DURATION || '30s' },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{name:health}': ['p(95)<100'],
    'http_req_duration{name:login_page}': ['p(95)<400'],
    'http_req_duration{name:api_projects}': ['p(95)<500'],
  },
};

export default function () {
  group('health', () => {
    const r = http.get(`${BASE}/health/live`, { tags: { name: 'health' } });
    check(r, { 'health 200': (res) => res.status === 200 });
  });
  group('login page', () => {
    const r = http.get(`${BASE}/login`, { tags: { name: 'login_page' } });
    check(r, { 'login 200': (res) => res.status === 200, 'has csrf': (res) => res.body.includes('_csrf') });
  });
  if (TOKEN) {
    group('api', () => {
      const r = http.get(`${BASE}/api/v1/projects?per_page=20`, { headers: { Authorization: `Bearer ${TOKEN}` }, tags: { name: 'api_projects' } });
      check(r, { 'api 200': (res) => res.status === 200 });
    });
  }
}
