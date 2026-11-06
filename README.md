# Yii3 Enterprise Platform

> Reusable, secure and scalable foundation for corporate and government information systems on **Yii3 · PHP 8.3 · PostgreSQL 17 · Redis · Kafka**.
>
> **Author & maintainer: [ShokirjonMK](https://github.com/ShokirjonMK)** · Repository: <https://github.com/ShokirjonMK/Yii3-Enterprise-Platform>

New projects start with login, MFA, SSO (OneID / corporate OIDC), RBAC, multi-tenancy, a 24-section Platform Control
Center, REST API, background jobs, scheduler, Kafka event streaming, audit, monitoring, Docker/Kubernetes and CI/CD
already in place — teams only write business modules.

## Quick start (Windows 11 + Docker Desktop/WSL2, macOS, Linux)

```bash
git clone https://github.com/ShokirjonMK/Yii3-Enterprise-Platform.git
cd Yii3-Enterprise-Platform
./scripts/platform.sh init      # creates .env with random local secrets
./scripts/platform.sh build     # PHP 8.3 image
./scripts/platform.sh up        # nginx, php-fpm, workers, PostgreSQL, Redis, Kafka, S3, Mailpit
./scripts/platform.sh install   # migrations, RBAC, reference data, one-time superadmin (password shown once)
./scripts/platform.sh kafka-provision
./scripts/platform.sh demo      # optional: 1-2 demo records in every admin section
./scripts/platform.sh fast      # FAST mode: production images, ~10-50 ms per page, ~1000 req/s locally
```

Two runtime modes share the same data volumes:

| Mode | Command | Use for | Speed (Windows laptop) |
|---|---|---|---|
| **fast** | `./scripts/platform.sh fast` | using / demoing the platform | `/login` ≈ 17 ms, admin pages 25-50 ms, k6: ~1000 req/s, p95 57 ms |
| dev | `./scripts/platform.sh up` | changing code (live reload, Developer Tools, Gii, tests) | ≈ 200-300 ms (host bind mount) |

`fast` must be re-run after code changes (it rebuilds the images).

Open <http://localhost:8090> (port = `HTTP_PORT` in `.env`). Mail: <http://localhost:8025>.
On first login the superadmin must change the temporary password and enrol MFA.

## Documentation

| | |
|---|---|
| [New project guide (uz)](docs/NEW_PROJECT_GUIDE.md) | starting a new project from the platform: repo, renaming, first run, modules, extra databases, API, tests, production checklist |
| [Databases](docs/DATABASES.md) | named connections of any engine + the Multi-DB demo module (MariaDB, PostgreSQL, MongoDB, ClickHouse) |
| [Architecture](docs/ARCHITECTURE.md) | modular monolith, layers, request pipeline, async processing |
| [Security](docs/SECURITY.md) | controls, hardening, ASVS mapping |
| [Implementation status](docs/IMPLEMENTATION_STATUS.md) | what is implemented / tested / verified, with evidence |
| [AGENTS.md](AGENTS.md) | engineering conventions (how to add a module or admin section) |

---

© 2026 **ShokirjonMK** — Yii3 Enterprise Platform. All rights reserved.
