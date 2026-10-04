# syntax=docker/dockerfile:1
#
# Production image for the Laravel API (ADR-017, ADR-021). One image runs all four ECS
# services; only the command differs:
#   api           (default)  php artisan octane:frankenphp
#   worker                   php artisan queue:work
#   scheduler                php artisan schedule:work
#   outbox-relay             php artisan outbox:relay
# Consumers:                 php artisan messages:consume <name>

FROM dunglas/frankenphp:1-php8.4-bookworm AS base
# Apply Debian security fixes the base image may not have picked up yet.
RUN apt-get update && apt-get upgrade -y --no-install-recommends && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql pcntl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /app

# --- Dependencies + application code ------------------------------------------
FROM base AS build
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi

# --- Runtime ------------------------------------------------------------------
FROM base AS runtime
ARG APP_USER=app
RUN useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin ${APP_USER} \
    # FrankenPHP needs no extra privileges to bind port 8000.
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R ${APP_USER}:${APP_USER} /data/caddy /config/caddy
COPY --from=build --chown=${APP_USER}:${APP_USER} /app /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    OCTANE_SERVER=frankenphp

USER ${APP_USER}
EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:8000/health/live') === false ? 1 : 0);"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]
