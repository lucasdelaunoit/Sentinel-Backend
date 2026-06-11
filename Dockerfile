# ---------------------------------------------------------------------------
# Stage 1 — Composer dependencies (no dev, no scripts: artisan not available yet)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 2 — Production image (nginx + php-fpm in one container)
# Ships with pdo_pgsql, pdo_mysql, redis, opcache, etc. Runs as www-data.
# Listens on 8080 (http) — point Dokploy's domain/proxy at port 8080.
# ---------------------------------------------------------------------------
FROM serversideup/php:8.4-fpm-nginx AS app

# Autorun on container start: php artisan migrate --force,
# config:cache, route:cache, view:cache, event:cache, storage:link
ENV AUTORUN_ENABLED=true \
    PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

USER root

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

# Re-run composer scripts skipped in stage 1 (package:discover needs artisan)
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
