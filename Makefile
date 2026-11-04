# Yii3 Enterprise Platform — developer & release shortcuts (Linux / macOS / WSL2).
#
# Every target wraps the same commands as scripts/platform.sh (which also works in Git Bash on Windows),
# so both entrypoints stay equivalent. Run `make help` for the list.
#
#   make init build up install      # first start
#   make test ARGS="--testsuite unit"
#   make yii ARGS="migrate:up --no-interaction"
#   make prod-build IMAGE_REGISTRY=registry.example.com/platform IMAGE_TAG=1.2.3

SHELL         := /usr/bin/env bash
.SHELLFLAGS   := -euo pipefail -c
.DEFAULT_GOAL := help

COMPOSE        ?= docker compose
PLATFORM       := ./scripts/platform.sh
ARGS           ?=
PROFILES       ?=

# Bind-mounted source must be writable by the container user (www-data remapped to your uid/gid in the dev image).
ifeq ($(shell uname -s),Darwin)
export UID := 1000
export GID := 1000
else
export UID := $(shell id -u)
export GID := $(shell id -g)
endif

IMAGE_REGISTRY ?= yep
IMAGE_TAG      ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo local)
PHP_IMAGE      := $(IMAGE_REGISTRY)-php:$(IMAGE_TAG)
NGINX_IMAGE    := $(IMAGE_REGISTRY)-nginx:$(IMAGE_TAG)
ifneq ($(findstring /,$(IMAGE_REGISTRY)),)
PHP_IMAGE      := $(IMAGE_REGISTRY)/php:$(IMAGE_TAG)
NGINX_IMAGE    := $(IMAGE_REGISTRY)/nginx:$(IMAGE_TAG)
endif

.PHONY: help init build up up-monitoring down ps logs shell install yii composer kafka-provision \
        test test-unit test-integration test-http psalm cs cs-fix rector deps-analyse audit \
        metrics-token prod-build prod-push k8s-render k8s-validate

##@ Local stack
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage: make \033[36m<target>\033[0m [ARGS=...]\n"} \
	  /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } \
	  /^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

init: ## Create .env with random local secrets (idempotent) + runtime dirs
	$(PLATFORM) init

build: ## Build the dev PHP image
	$(PLATFORM) build

up: ## Start the stack (PROFILES="monitoring pooling" for optional profiles)
	$(PLATFORM) up $(PROFILES)

up-monitoring: metrics-token ## Start the stack + monitoring profile (Prometheus, Grafana, Jaeger, exporters)
	$(PLATFORM) up monitoring

down: ## Stop the stack (all profiles)
	$(PLATFORM) down

ps: ## Service status
	$(PLATFORM) ps

logs: ## Follow logs (ARGS="app worker")
	$(PLATFORM) logs $(ARGS)

shell: ## Shell into the app container
	$(PLATFORM) shell

install: ## composer install + ./yii platform:install (interactive, idempotent)
	$(PLATFORM) install

yii: ## Run a console command (ARGS="queue:work --once")
	$(PLATFORM) yii $(ARGS)

composer: ## Run composer in the app container (ARGS="validate --strict")
	$(PLATFORM) composer $(ARGS)

kafka-provision: ## Create Kafka topics from the registry (idempotent)
	$(PLATFORM) kafka-provision

metrics-token: ## Write runtime/metrics-token (from METRICS_TOKEN in .env) for the Prometheus scrape
	$(PLATFORM) metrics-token

##@ Quality & tests
test: ## Run PHPUnit (ARGS="--testsuite unit,integration")
	$(PLATFORM) test $(ARGS)

test-unit: ## Unit + integration suites (test database)
	$(PLATFORM) test --testsuite unit,integration

test-http: ## API / E2E / security suites against the running stack
	$(COMPOSE) exec -T app php vendor/bin/phpunit --testsuite api,e2e,security

psalm: ## Static analysis
	$(PLATFORM) psalm

cs: ## Code-style check (dry run)
	$(PLATFORM) cs

cs-fix: ## Apply code-style fixes
	$(COMPOSE) run --rm --no-deps app php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php --diff

rector: ## Rector dry run
	$(COMPOSE) run --rm --no-deps app php vendor/bin/rector process --dry-run

deps-analyse: ## composer-dependency-analyser
	$(COMPOSE) run --rm --no-deps app php vendor/bin/composer-dependency-analyser --config=composer-dependency-analyser.php

audit: ## composer audit (known vulnerable dependencies)
	$(COMPOSE) run --rm --no-deps app composer audit --locked

##@ Release
prod-build: ## Build production php + nginx images (IMAGE_REGISTRY, IMAGE_TAG)
	docker build --pull -f infra/docker/php/Dockerfile --target prod -t $(PHP_IMAGE) .
	docker build -f infra/docker/nginx/Dockerfile --build-arg PHP_IMAGE=$(PHP_IMAGE) -t $(NGINX_IMAGE) .
	@echo "built $(PHP_IMAGE) and $(NGINX_IMAGE)"

prod-push: ## Push production images
	docker push $(PHP_IMAGE)
	docker push $(NGINX_IMAGE)

k8s-render: ## Render a Kustomize overlay (ARGS=production|staging)
	docker run --rm -v "$(CURDIR)":/work -w /work registry.k8s.io/kubectl:v1.31.0 kustomize infra/kubernetes/overlays/$(or $(ARGS),production)

k8s-validate: ## Render both overlays and validate with kubeconform
	./scripts/ci/k8s-validate.sh
