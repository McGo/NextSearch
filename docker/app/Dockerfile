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
FROM dunglas/frankenphp:1.12-php8.4 AS runtime

# poppler-utils renders PDF pages to images (pdftoppm) and provides pdfinfo.
# supervisor drives the optional all-in-one role (serve + worker + scheduler).
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      poppler-utils \
      supervisor \
      curl \
      ca-certificates \
 && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
      pdo_pgsql \
      pgsql \
      redis \
      intl \
      zip \
      bcmath \
      gd \
      exif \
      pcntl \
      opcache \
      sockets

COPY docker/app/php.ini /usr/local/etc/php/conf.d/nextsearch.ini
COPY docker/app/supervisord.conf /etc/supervisor/nextsearch.conf
COPY docker/app/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /app
COPY --from=vendor /app /app

# FrankenPHP serves unencrypted on 8080 — TLS and port exposure are the
# operator's concern; only the Nuxt container faces the outside anyway.
ENV SERVER_NAME=":8080" \
    FRANKENPHP_CONFIG="" \
    CADDY_GLOBAL_OPTIONS="auto_https off"

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["app-entrypoint"]
CMD ["serve"]
