#!/usr/bin/env sh
# Starts the application inside a CI job for the HTTP suites (api / e2e / security):
#   migrations + RBAC + seed against the job's PostgreSQL service, then the PHP built-in server
#   (public/index.php handles the cli-server router) on 127.0.0.1:${APP_PORT:-8080}, and waits for /health/ready.
set -eu

PORT="${APP_PORT:-8080}"
mkdir -p runtime tests/_output

php yii migrate:up --no-interaction
php yii rbac:sync
php yii platform:seed

# PHP_CLI_SERVER_WORKERS lets the built-in server handle parallel requests (htmx + assets).
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" \
  nohup php -S "0.0.0.0:$PORT" -t public public/index.php > runtime/php-server.log 2>&1 &
echo $! > runtime/php-server.pid

i=0
until curl -fsS "http://127.0.0.1:$PORT/health/ready" >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "application did not become ready" >&2
    tail -n 100 runtime/php-server.log >&2 || true
    exit 1
  fi
  sleep 1
done
echo "application ready on http://127.0.0.1:$PORT"
