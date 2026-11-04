# Local development setup

The local stack is `compose.yaml`: nginx, PHP-FPM app, worker, scheduler, outbox relay, Kafka consumer,
PostgreSQL 17, Redis 7.4, Kafka 3.9 (KRaft), SeaweedFS (S3 API) and Mailpit; optional profiles `monitoring`
(Prometheus, Alertmanager, Grafana, OpenTelemetry Collector, Jaeger, exporters) and `pooling` (PgBouncer).
It is a single-host development environment, **not** a high-availability deployment (see [DEPLOYMENT.md](DEPLOYMENT.md)).

## 1. Prerequisites

| | Windows 11 | macOS | Linux |
|---|---|---|---|
| Docker | Docker Desktop ≥ 4.30 with the **WSL2 backend** | Docker Desktop ≥ 4.30 (or OrbStack / Colima) | Docker Engine ≥ 25 + Compose plugin v2.24+ |
| Shell | **Git Bash** (comes with Git for Windows) or a WSL2 distro (Ubuntu) | zsh/bash | bash |
| Tools | Git; optional `make` inside WSL2 | Git, GNU sed (`brew install gnu-sed`, see §6), optional make | Git, make |
| Resources | ≥ 4 CPUs, 8 GB RAM for Docker (Settings → Resources / `.wslconfig`) | same | same |

Everything PHP-related (PHP 8.3, extensions, Composer) runs inside the `yep-php:dev` image — no local PHP needed.

**Windows performance tip:** cloning into the WSL2 filesystem (`\\wsl$\Ubuntu\home\<you>\…`) and running the commands
from the WSL shell gives much faster bind mounts than a `C:\` checkout. A `C:\` checkout with Git Bash works too.

## 2. First start

```bash
git clone https://github.com/ShokirjonMK/Yii3-Enterprise-Platform.git
cd Yii3-Enterprise-Platform

./scripts/platform.sh init      # creates .env with random local secrets (idempotent) + runtime dirs
./scripts/platform.sh build     # builds yep-php:dev (uid/gid mapped to yours)
./scripts/platform.sh up        # starts the default services
./scripts/platform.sh install   # composer install + `php yii platform:install` (migrations, RBAC, seed, first superadmin)
```

`install` prints the one-time superadmin password; you must change it and enrol MFA on first login.
Equivalent `make` targets exist on Linux/macOS/WSL: `make init build up install` (`make help` lists all).

Optional:

```bash
./scripts/platform.sh up monitoring        # + Prometheus/Grafana/Jaeger/exporters (writes runtime/metrics-token first)
./scripts/platform.sh kafka-provision      # create Kafka topics from the registry (idempotent)
./scripts/platform.sh up pooling           # + PgBouncer
```

## 3. Daily commands

| Task | Command |
|---|---|
| status / logs | `./scripts/platform.sh ps`, `./scripts/platform.sh logs app worker` |
| console | `./scripts/platform.sh yii <command>` (e.g. `migrate:up`, `rbac:sync`, `queue:work --once`) |
| composer | `./scripts/platform.sh composer <args>` |
| tests (unit + integration, DB `platform_test`) | `./scripts/platform.sh test --testsuite unit,integration` |
| HTTP suites against the running stack | `docker compose exec -T app php vendor/bin/phpunit --testsuite api,e2e,security` |
| static analysis / code style | `./scripts/platform.sh psalm`, `./scripts/platform.sh cs` |
| shell | `./scripts/platform.sh shell` |
| stop | `./scripts/platform.sh down` (volumes are kept; `docker compose down -v` deletes all local data) |
| production images | `make prod-build` |
| render / validate Kubernetes manifests | `make k8s-render ARGS=staging`, `scripts/ci/k8s-validate.sh` |

## 4. URLs and ports

Host ports come from `.env` (defaults in brackets). This checkout currently uses `HTTP_PORT=8090`.

| Service | URL | Variable |
|---|---|---|
| Application (nginx) | http://localhost:8090 → `/login`, `/admin` | `HTTP_PORT` [8080] |
| Health | http://localhost:8090/health/live, `/health/ready` | — |
| Metrics | http://localhost:8090/metrics (`Authorization: Bearer $METRICS_TOKEN`) | `METRICS_TOKEN` |
| Mailpit (all outgoing e-mail) | http://localhost:8025 | `MAILPIT_UI_PORT` |
| PostgreSQL | `localhost:55432`, db `platform` / `platform_test`, user `platform`, password `DB_PASSWORD` | `PG_PORT` |
| S3 (SeaweedFS) | inside the network only: `http://s3:8333`, bucket `platform`, key `S3_KEY` / `S3_SECRET` | — |
| Prometheus | http://localhost:9090 | `PROMETHEUS_PORT` |
| Grafana | http://localhost:3000 (admin / `GRAFANA_ADMIN_PASSWORD`) | `GRAFANA_PORT` |
| Jaeger | http://localhost:16686 | `JAEGER_UI_PORT` |

Redis and Kafka are not published to the host; use `docker compose exec redis redis-cli -a "$REDIS_PASSWORD"` or
`docker compose exec kafka /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --list`.

**S3 / object storage.** MinIO community images are no longer published, so SeaweedFS provides the S3 API locally
(`infra/docker/s3/entrypoint.sh` creates the bucket and the access key from `.env`). The app only speaks S3
(Flysystem AWS adapter), so MinIO, Ceph RGW or AWS S3 work unchanged in other environments. Inspect objects with any S3
client on the backend network, e.g.
`docker run --rm --network yep_backend -e AWS_ACCESS_KEY_ID=$S3_KEY -e AWS_SECRET_ACCESS_KEY=$S3_SECRET amazon/aws-cli --endpoint-url http://s3:8333 s3 ls s3://platform`.

**Tracing.** Set `OTEL_ENABLED=true` in `.env`, start the `monitoring` profile and restart the PHP services
(`docker compose up -d app worker`); traces appear in Jaeger under `yii3-platform`.

## 5. Configuration

- `.env` is created from `.env.example` by `init`; every `__GENERATE__` becomes an independent random value. Never commit
  `.env` (it is gitignored), never reuse local secrets elsewhere. Production never uses a `.env` file.
- All variables the application reads are in `config/common/params.php`; `.env.example` documents them.
- `TRUSTED_PROXIES` must contain the Docker network range (default `172.16.0.0/12,10.0.0.0/8`) so client IPs from nginx
  are honoured.

## 6. Troubleshooting

| Symptom | Fix |
|---|---|
| Git Bash mangles container paths (`C:/Program Files/Git/app/...`, "no such file") | prefix with `MSYS_NO_PATHCONV=1` (scripts/platform.sh already exports it): `MSYS_NO_PATHCONV=1 docker compose exec app php yii list`. For `docker run -v`, use `"$(pwd -W)"` as the host path. |
| `bind: address already in use` / port is already allocated | another stack uses the port: change `HTTP_PORT`, `PG_PORT`, `MAILPIT_UI_PORT`, `PROMETHEUS_PORT`, `GRAFANA_PORT`, `JAEGER_UI_PORT` in `.env`, then `./scripts/platform.sh up`. Find the owner: `netstat -ano \| findstr :8080` (Windows) / `lsof -i :8080` (macOS/Linux). |
| `Permission denied` writing `runtime/`, `public/assets/`, `tests/_output` | bind-mount ownership: rebuild so `www-data` gets your uid/gid (`make build` exports `UID`/`GID`; in Git Bash/plain `scripts/platform.sh build` the image defaults to 1000:1000, which is what Docker Desktop expects) or run `./scripts/platform.sh fix-perms`. On macOS Docker Desktop file sharing maps ownership automatically. |
| `.env` generation fails on macOS (`sed: 1: "0,/__GENERATE__/...": invalid command`) | `init` uses GNU sed syntax: `brew install gnu-sed` and run with `PATH="$(brew --prefix)/opt/gnu-sed/libexec/gnubin:$PATH" ./scripts/platform.sh init`, or copy `.env.example` and fill the `__GENERATE__` values with `openssl rand -hex 32`. |
| `install`/`up` fails: `DB_PASSWORD must be set` | run `./scripts/platform.sh init` first (or fix `.env`). |
| Prometheus does not start: `runtime/metrics-token` missing | `./scripts/platform.sh metrics-token` (done automatically by `up monitoring`). Target `platform-app` 401 → token in `.env` changed: re-run the command and `docker compose restart prometheus`. |
| Kafka unhealthy after a hard shutdown / cluster id mismatch | `docker compose stop kafka && docker volume rm yep_kafka_data && ./scripts/platform.sh up && ./scripts/platform.sh kafka-provision` (local topic data is lost; the outbox re-publishes pending events). |
| Very slow page loads on Windows | move the checkout into the WSL2 filesystem; exclude it from Windows Defender real-time scanning. |
| CRLF breaks shell scripts in containers (`/bin/sh^M`) | `.gitattributes` enforces LF; if you cloned before, run `git add --renormalize . && git checkout -- .`. |
| `/login` returns 500 "cookie_secure is on" when testing a prod image locally | production mode requires HTTPS (`__Host-` session cookie): send `X-Forwarded-Proto: https` through the prod nginx, or keep `APP_ENV=dev` locally. |
| Reset everything | `./scripts/platform.sh down && docker compose down -v` then the first-start steps again. |

— Author: ShokirjonMK
