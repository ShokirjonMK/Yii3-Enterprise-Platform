# syntax=docker/dockerfile:1.7
#
# Yii3 Enterprise Platform — PHP 8.3 FPM / CLI image.
# Author: ShokirjonMK — https://github.com/ShokirjonMK/Yii3-Enterprise-Platform
# Targets:
#   base  — runtime extensions only
#   dev   — + composer, pcov, dev tooling (bind-mounted source)
#   prod  — immutable image with vendor (no dev deps), non-root, opcache tuned
#
FROM composer/composer:2-bin AS composer
FROM mlocati/php-extension-installer:2 AS ext-installer

FROM php:8.3-fpm-bookworm AS base

ENV LC_ALL=C.UTF-8 \
    TZ=UTC \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1 \
    PHP_FPM_MAX_CHILDREN=20 \
    PHP_FPM_PM=dynamic

COPY --from=ext-installer /usr/bin/install-php-extensions /usr/local/bin/

# PostgreSQL 17 client tools (pg_dump/pg_restore must match the server major version) from the PGDG repository
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
 && install -d /usr/share/postgresql-common/pgdg \
 && curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc \
 && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends unzip git postgresql-client-17 libfcgi-bin procps \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions \
      opcache intl mbstring pdo_pgsql pgsql redis rdkafka zip gd bcmath pcntl sockets sodium apcu \
      mongodb pdo_mysql pdo_sqlite pdo_sqlsrv pdo_oci

COPY infra/docker/php/conf.d/platform.ini /usr/local/etc/php/conf.d/zz-platform.ini
COPY infra/docker/php/fpm/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY infra/docker/php/healthcheck.sh /usr/local/bin/php-fpm-healthcheck
RUN chmod +x /usr/local/bin/php-fpm-healthcheck

WORKDIR /app

# ---------------------------------------------------------------- dev
FROM base AS dev
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN install-php-extensions pcov \
 && groupmod -o -g ${GROUP_ID} www-data && usermod -o -u ${USER_ID} -g www-data www-data
COPY --from=composer /composer /usr/bin/composer
COPY infra/docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
USER www-data

# ---------------------------------------------------------------- prod builder
FROM base AS prod-builder
COPY --from=composer /composer /usr/bin/composer
COPY composer.json composer.lock /app/
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-dev --no-scripts --no-plugins --no-autoloader --no-progress --no-interaction
COPY . /app
# - dump-autoload also regenerates config/.merge-plan.php (yiisoft/config plugin) for --no-dev packages
# - repository files that are not needed at runtime are removed (tests, docs, infra, CI, tooling configs)
# - UI assets are pre-published into public/assets/<hash> with the vendor AssetPublisher, so the php image and
#   the nginx image (infra/docker/nginx/Dockerfile copies /app/public from this image) contain identical,
#   immutable asset directories and the read-only root filesystem never needs to be written at runtime.
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
 && rm -rf tests docs infra observability scripts .git .gitlab-ci.yml .github Makefile compose.yaml compose.*.yaml \
           phpunit.xml.dist psalm.xml rector.php .php-cs-fixer.php composer-dependency-analyser.php \
           .editorconfig .env.example login.html yii.bat ./*.md runtime/* public/assets/* \
 && mkdir -p runtime public/assets \
 && php -r 'require "vendor/autoload.php"; \
      $a = new Yiisoft\Aliases\Aliases(["@root" => "/app", "@assets" => "/app/public/assets", "@assetsUrl" => "/assets", "@assetsSource" => "/app/assets"]); \
      [$path] = (new Yiisoft\Assets\AssetPublisher($a))->publish(new App\Shared\Infrastructure\View\PlatformAsset()); \
      fwrite(STDOUT, "published assets: $path\n");' \
 && test -n "$(ls -A public/assets)"

# ---------------------------------------------------------------- prod
FROM base AS prod
ARG VERSION=dev
ARG REVISION=unknown
ARG CREATED=unknown
LABEL org.opencontainers.image.title="yii3-enterprise-platform-php" \
      org.opencontainers.image.description="Yii3 Enterprise Platform — PHP 8.3 FPM/CLI production image (web, workers, scheduler, relay, consumers)" \
      org.opencontainers.image.authors="ShokirjonMK" \
      org.opencontainers.image.vendor="ShokirjonMK" \
      org.opencontainers.image.source="https://github.com/ShokirjonMK/Yii3-Enterprise-Platform" \
      org.opencontainers.image.url="https://github.com/ShokirjonMK/Yii3-Enterprise-Platform" \
      org.opencontainers.image.documentation="https://github.com/ShokirjonMK/Yii3-Enterprise-Platform/blob/main/docs/DEPLOYMENT.md" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${REVISION}" \
      org.opencontainers.image.created="${CREATED}"
ENV APP_ENV=prod APP_DEBUG=false PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
# Build tooling is not needed at runtime (composer is never copied into this stage).
RUN apt-get purge -y git unzip gnupg gpg gpg-agent dirmngr gpgsm \
 && rm -rf /var/lib/apt/lists/* /var/cache/apt/* /usr/local/bin/install-php-extensions
COPY infra/docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
# Code is owned by root and read-only for the runtime user; only runtime/ is writable
# (in Kubernetes runtime/ and /tmp are emptyDir volumes and the root filesystem is read-only).
COPY --from=prod-builder /app /app
RUN chown -R www-data:www-data /app/runtime && chmod -R a-w /app/src /app/config /app/vendor /app/public
USER www-data
HEALTHCHECK --interval=15s --timeout=5s --retries=3 CMD php-fpm-healthcheck || exit 1
