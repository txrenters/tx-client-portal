# Production image for tx-client (app.texasrenters.com), following the shared
# company VPS pattern (tx-chatbot-vps, tx-maintenance-vps). The build context is
# the repository root; see docker-compose.yml.
#
#   build   - Debian with PHP and Node: the Wayfinder Vite plugin runs
#             `php artisan wayfinder:generate` during `npm run build`, so
#             composer must have run first.
#   runtime - Alpine. vendor/ is pure PHP and public/build is static, so
#             nothing compiled in the build stage ships forward.

# ---------------------------------------------------------------------------
# Stage 1: dependencies and frontend assets.
# ---------------------------------------------------------------------------
FROM php:8.5.12-cli-bookworm AS build

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libzip-dev libicu-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip bcmath intl soap exif \
    # The exact patch is pinned so a rebuild cannot reuse a cached layer from
    # before the October 2026 Node security release (24.22.1); it may rise,
    # never fall. Node only runs in this stage -- no runtime image ships it.
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs=24.22.1-1nodesource1 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependency manifests first, so application edits do not invalidate the
# (slow) dependency layers.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# The Wayfinder plugin boots the app during `npm run build`, and Laravel needs
# its storage paths to exist; the ignore file leaves them out of the context.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev

# Vite inlines VITE_* values into the bundle at build time, so they are build
# arguments rather than runtime env. Changing any of them needs a rebuild.
# configureEcho() reads VITE_REVERB_HOST/PORT/SCHEME for the browser's socket.
ARG VITE_APP_NAME
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME
RUN npm run build && rm -rf node_modules

# ---------------------------------------------------------------------------
# Stage 2: PHP-FPM runtime. Shared by app, queue, scheduler, reverb and
# migrate -- they differ only in the command they run.
# ---------------------------------------------------------------------------
FROM php:8.5.12-fpm-alpine AS app

# fcgi provides cgi-fcgi, which the health check uses to reach FPM directly.
# soap backs PropertywareSoap; gd/exif back intervention/image; pcntl lets
# reverb and queue:work stop cleanly on SIGTERM.
RUN apk add --no-cache fcgi libpng libjpeg-turbo freetype libwebp libzip icu-libs libxml2 libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
        libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libzip-dev icu-dev libxml2-dev postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip pdo_pgsql bcmath pcntl intl soap exif \
    && apk del .build-deps

COPY deploy/docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY deploy/docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

COPY --from=build --chown=www-data:www-data /app /var/www/html

RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs storage/app/public storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY deploy/docker/entrypoint.sh /usr/local/bin/entrypoint
COPY deploy/docker/healthcheck-fpm.sh /usr/local/bin/healthcheck-fpm
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/healthcheck-fpm

USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["healthcheck-fpm"]

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Stage 3: nginx. public/ is baked in rather than shared through a volume, so
# every deploy ships its own assets.
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY deploy/docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/public /var/www/html/public

# .htaccess is Apache-only; nginx must not serve it.
RUN rm -f /var/www/html/public/.htaccess \
    && ln -sfn ../storage/app/public /var/www/html/public/storage

# Adds this build's assets to the shared history on start; see the script.
COPY deploy/docker/keep-built-assets.sh /docker-entrypoint.d/40-keep-built-assets.sh
RUN chmod +x /docker-entrypoint.d/40-keep-built-assets.sh

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO /dev/null http://127.0.0.1/up || exit 1
