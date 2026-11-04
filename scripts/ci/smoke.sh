#!/usr/bin/env sh
# Post-deploy smoke test (GitLab CI smoke-staging / verify-production, also usable by hand).
#
#   scripts/ci/smoke.sh https://staging.example.org
#
# Checks: /health/live 200, /health/ready 200 + "status":"up|degraded", /login 200 with security headers,
# optional API health (SMOKE_API_PATH, default /api/v1/health; 404 only warns unless SMOKE_REQUIRE_API=1),
# optional /metrics with METRICS_TOKEN (never printed).
set -eu

BASE="${1:?base url}"
BASE="${BASE%/}"
RETRIES="${SMOKE_RETRIES:-10}"
API_PATH="${SMOKE_API_PATH:-/api/v1/health}"
fail=0

code() { curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$@" || echo 000; }

check() { # name expected url [curl args]
  name="$1"; want="$2"; url="$3"; shift 3
  i=0
  while :; do
    got="$(code "$@" "$url")"
    [ "$got" = "$want" ] && { echo "PASS $name ($got)"; return 0; }
    i=$((i + 1))
    [ "$i" -ge "$RETRIES" ] && { echo "FAIL $name: expected $want, got $got"; fail=1; return 1; }
    sleep 3
  done
}

check "health/live" 200 "$BASE/health/live" || true
check "health/ready" 200 "$BASE/health/ready" || true
ready="$(curl -sS --max-time 10 "$BASE/health/ready" || true)"
case "$ready" in
  *'"status":"up"'*|*'"status":"degraded"'*) echo "PASS readiness body: $ready" ;;
  *) echo "FAIL readiness body: $ready"; fail=1 ;;
esac

check "login page" 200 "$BASE/login" || true
headers="$(curl -sSI --max-time 10 "$BASE/login" || true)"
for h in Content-Security-Policy X-Frame-Options X-Content-Type-Options Strict-Transport-Security; do
  if printf '%s' "$headers" | grep -qi "^$h:"; then echo "PASS header $h"; else
    case "$BASE" in https://*) echo "FAIL header $h missing"; fail=1 ;; *) echo "WARN header $h missing (plain http)";; esac
  fi
done
if printf '%s' "$headers" | grep -qiE '^x-powered-by:|^server: nginx/[0-9]'; then echo "FAIL server/version disclosure"; fail=1; fi

api="$(code "$BASE$API_PATH")"
case "$api" in
  200) echo "PASS api health ($api)" ;;
  404) if [ "${SMOKE_REQUIRE_API:-0}" = "1" ]; then echo "FAIL api health 404"; fail=1; else echo "WARN api health not exposed at $API_PATH (404)"; fi ;;
  *) echo "FAIL api health ($api)"; fail=1 ;;
esac

if [ -n "${METRICS_TOKEN:-}" ]; then
  # /metrics is restricted to private networks at nginx; from outside the cluster 403 is the expected answer.
  m="$(code -H "Authorization: Bearer $METRICS_TOKEN" "$BASE/metrics")"
  case "$m" in 200|403) echo "PASS metrics endpoint ($m)" ;; *) echo "FAIL metrics endpoint ($m)"; fail=1 ;; esac
fi
m="$(code "$BASE/metrics")"
case "$m" in 401|403) echo "PASS metrics without token rejected ($m)" ;; *) echo "FAIL metrics without token returned $m"; fail=1 ;; esac

exit "$fail"
