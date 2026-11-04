# syntax=docker/dockerfile:1.7
#
# Yii3 Enterprise Platform — PRODUCTION nginx image (static assets baked in, non-root).
# Author: ShokirjonMK — https://github.com/ShokirjonMK/Yii3-Enterprise-Platform
#
# public/ (incl. the pre-published, hashed public/assets/<hash>) is copied from the prod PHP image so both
# containers of the web pod serve byte-identical files:
#
#   docker build -f infra/docker/php/Dockerfile   --target prod -t registry/yep-php:1.2.3 .
#   docker build -f infra/docker/nginx/Dockerfile --build-arg PHP_IMAGE=registry/yep-php:1.2.3 -t registry/yep-nginx:1.2.3 .
#
ARG PHP_IMAGE=yep-php:prod
ARG NGINX_IMAGE=nginxinc/nginx-unprivileged:1.27-alpine

FROM ${PHP_IMAGE} AS php

FROM ${NGINX_IMAGE} AS prod
ARG VERSION=dev
ARG REVISION=unknown
ARG CREATED=unknown
LABEL org.opencontainers.image.title="yii3-enterprise-platform-nginx" \
      org.opencontainers.image.description="Yii3 Enterprise Platform — nginx (non-root) with baked public/ assets, sidecar of the PHP-FPM web pod" \
      org.opencontainers.image.authors="ShokirjonMK" \
      org.opencontainers.image.vendor="ShokirjonMK" \
      org.opencontainers.image.source="https://github.com/ShokirjonMK/Yii3-Enterprise-Platform" \
      org.opencontainers.image.url="https://github.com/ShokirjonMK/Yii3-Enterprise-Platform" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${REVISION}" \
      org.opencontainers.image.created="${CREATED}"
USER root
# Default trusted proxies = private ranges used by cloud LBs / ingress controllers / kube-proxy SNAT.
# Override per environment by mounting a ConfigMap at /etc/nginx/snippets/real-ip.conf.
RUN rm -f /etc/nginx/conf.d/*.conf \
 && mkdir -p /etc/nginx/snippets \
 && printf '%s\n' \
      '# Trusted load balancers / ingress (X-Forwarded-For is only honoured from these)' \
      'set_real_ip_from 10.0.0.0/8;' \
      'set_real_ip_from 172.16.0.0/12;' \
      'set_real_ip_from 192.168.0.0/16;' \
      'real_ip_header X-Forwarded-For;' \
      'real_ip_recursive on;' \
      > /etc/nginx/snippets/real-ip.conf
COPY infra/docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=php /app/public /app/public
# PHP sources are not served by nginx; index.php only has to exist for try_files / SCRIPT_FILENAME.
RUN find /app/public -name '*.php' ! -name index.php -delete \
 && chown -R root:root /app/public && chmod -R a-w /app/public \
 && nginx -t \
 && rm -f /tmp/nginx.pid
USER 101
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD wget -qO- http://127.0.0.1:8080/nginx-health || exit 1
