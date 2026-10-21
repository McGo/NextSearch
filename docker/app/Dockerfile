# ---------------------------------------------------------------------------
# Stage 1 — dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---------------------------------------------------------------------------
# Stage 2 — runtime
# ---------------------------------------------------------------------------
# Shared PHP base image (php-fpm + nginx under supervisord, Alpine). It ships
# every extension NextSearch needs (pdo_pgsql, pgsql, redis, intl, zip, bcmath,
# gd, exif, pcntl, opcache, sockets) plus supervisor, curl and su-exec.
# Source: https://hub.docker.com/r/mirkohaaser/php
FROM mirkohaaser/php:8.5.12-nginx AS runtime

# poppler-utils renders PDF pages to images (pdftoppm) and provides pdfinfo.
RUN apk add --no-cache poppler-utils

COPY docker/app/php.ini /usr/local/etc/php/conf.d/nextsearch.ini
COPY docker/app/supervisord.conf /etc/supervisor/nextsearch.conf
COPY docker/app/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /app
COPY --from=vendor /app /app

# The release tag, passed by CI. The MCP server reports it to its clients;
# a local build says "dev".
ARG APP_VERSION=dev

# nginx serves /app/public unencrypted on 8080 — TLS and port exposure are the
# operator's concern; only the Nuxt container faces the outside anyway.
# The PHP limits mirror docker/app/php.ini so nginx (body size, read timeout)
# agrees with PHP. max_execution_time 0 becomes a one-hour nginx timeout.
ENV APP_VERSION=$APP_VERSION \
    APP_ROOT=/app \
    PORT=8080 \
    PHP_MEMORY_LIMIT=512M \
    PHP_MAX_EXECUTION_TIME=0 \
    PHP_UPLOAD_MAX_FILESIZE=200M \
    PHP_POST_MAX_SIZE=200M

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["app-entrypoint"]
CMD ["serve"]
