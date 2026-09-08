# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# 1) PHP dependencies (composer install, no dev packages, cached separately
#    from app source so `composer.lock` changes are the only thing that bust
#    this layer).
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---------------------------------------------------------------------------
# 2) Frontend assets (Vite build). Kept separate from the PHP image entirely
#    so the runtime image never carries Node.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# ---------------------------------------------------------------------------
# 3) Runtime image: PHP-FPM + nginx + supervisor in one container.
#    Simple to operate for a system of this size (one image, one process
#    supervisor); scale horizontally by running more replicas of this image
#    behind a load balancer, all pointed at the same Postgres/Redis/object
#    storage — see docker-compose.yml.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-libs \
        postgresql-client \
        icu-libs \
        libzip \
        oniguruma \
        curl \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

# Opcache tuned for production (no filesystem stat on every request).
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
# Overrides the base image's 5-worker pool default — see the file itself.
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

COPY --from=vendor /app ./
COPY --from=frontend /app/public/build ./public/build

# The base php-fpm-alpine image's default pool (www.conf) already runs
# workers as www-data:www-data — reuse it rather than creating a new user
# that php-fpm wouldn't actually run as.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
