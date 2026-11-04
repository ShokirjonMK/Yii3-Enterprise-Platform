#!/usr/bin/env bash
# Developer entrypoint that works in Git Bash (Windows), WSL2, macOS and Linux.
# The Makefile wraps the same commands for environments with `make`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
export MSYS_NO_PATHCONV=1
COMPOSE="docker compose"

rand_hex() { head -c "${1:-32}" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

cmd_init() {
  if [[ -f .env ]]; then
    echo ".env already exists — leaving it untouched."
  else
    cp .env.example .env
    # Replace each __GENERATE__ with an independent random value
    while grep -q "__GENERATE__" .env; do
      sed -i.bak "0,/__GENERATE__/s//$(rand_hex 32)/" .env
    done
    rm -f .env.bak
    echo "Created .env with random local secrets."
  fi
  mkdir -p runtime public/assets tests/_output
}

cmd_fix_perms() {
  # Docker Desktop bind mounts: make writable dirs accessible for the container user
  $COMPOSE run --rm --no-deps -u 0 app sh -c 'chmod -R a+rwX runtime public/assets tests config 2>/dev/null || true'
}

cmd_metrics_token() {
  # Prometheus (monitoring profile) reads the /metrics bearer token from runtime/metrics-token
  # (authorization.credentials_file). runtime/ is gitignored, so the secret never lands in git.
  [[ -f .env ]] || { echo ".env not found — run: scripts/platform.sh init" >&2; exit 1; }
  local token
  token="$(grep -E '^METRICS_TOKEN=' .env | tail -n1 | cut -d= -f2- | tr -d '"'"'"'\r')"
  [[ -n "$token" ]] || { echo "METRICS_TOKEN is empty in .env" >&2; exit 1; }
  mkdir -p runtime
  (umask 077; printf '%s' "$token" > runtime/metrics-token)
  chmod 644 runtime/metrics-token 2>/dev/null || true   # read by the prometheus container user (nobody)
  echo "Wrote runtime/metrics-token"
}

usage() {
  cat <<EOF
Usage: scripts/platform.sh <command> [args]

  init            Create .env with random secrets (idempotent)
  build           Build PHP images
  up [profile..]  Start the stack (e.g. "up monitoring")
  down            Stop the stack
  install         Install composer deps, run ./yii platform:install (interactive)
  yii <args>      Run a console command in the app container
  composer <args> Run composer in the app container
  kafka-provision Create Kafka topics from the registry (idempotent)
  metrics-token   Write runtime/metrics-token for the Prometheus scrape (done by "up monitoring")
  test [args]     Run the PHPUnit test suites (uses the test database)
  psalm           Static analysis
  cs              Code-style check
  logs [svc]      Follow logs
  ps              Service status
  shell           Shell into the app container
EOF
}

case "${1:-}" in
  init) cmd_init ;;
  build) $COMPOSE build ;;
  up)
    shift
    profiles=()
    for p in "$@"; do
      profiles+=(--profile "$p")
      [[ "$p" == "monitoring" ]] && cmd_metrics_token
    done
    $COMPOSE "${profiles[@]}" up -d --remove-orphans
    ;;
  down) $COMPOSE --profile monitoring --profile pooling down --remove-orphans ;;
  fix-perms) cmd_fix_perms ;;
  install)
    cmd_init
    $COMPOSE run --rm --no-deps app composer install --no-interaction
    cmd_fix_perms
    $COMPOSE run --rm app php yii platform:install
    ;;
  kafka-provision)
    $COMPOSE exec app php yii kafka:provision ${2:+--replication-factor=$2}
    $COMPOSE exec kafka sh /provision/kafka-topics.sh
    ;;
  metrics-token) cmd_metrics_token ;;
  yii) shift; $COMPOSE exec app php yii "$@" ;;
  composer) shift; $COMPOSE run --rm --no-deps app composer "$@" ;;
  test) shift; $COMPOSE exec -e APP_ENV=test app php vendor/bin/phpunit "$@" ;;
  psalm) $COMPOSE run --rm --no-deps app php vendor/bin/psalm --no-cache ;;
  cs) $COMPOSE run --rm --no-deps app php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --dry-run --diff ;;
  logs) shift; $COMPOSE logs -f --tail=200 "$@" ;;
  ps) $COMPOSE ps ;;
  shell) $COMPOSE exec app bash ;;
  *) usage ;;
esac
