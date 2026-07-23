# syntax=docker/dockerfile:1

# --- Stage 1: install PHP deps without shipping Composer or dev tooling in the final image ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-reqs

# --- Stage 2: runtime image — php-fpm + nginx + supervisor in one container ---
# (DO App Platform runs one container per component; bundling web server +
# app server here avoids needing a second component just to serve requests.)
FROM php:8.2-fpm-alpine AS app

RUN apk add --no-cache \
        ca-certificates \
        nginx \
        supervisor \
        mariadb-client \
        curl \
        libpng \
        libjpeg-turbo \
        freetype \
        libzip \
        icu-libs \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        curl-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        pdo_mysql \
        bcmath \
        exif \
        pcntl \
        zip \
        intl \
        mbstring \
        curl \
        opcache \
    && apk del .build-deps

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/framework/testing storage/logs storage/app/public storage/app/private \
             storage/app/backups bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R ug+rwX storage bootstrap/cache

# nginx.conf.template sets `user www-data;` so worker processes match php-fpm's user,
# but the nginx apk package's own runtime dirs (client_body temp, pid, ...) are still
# owned by its default `nginx` user/group — rechown so www-data can actually write
# there (fixes "open() .../client_body/... Permission denied" on any request nginx
# needs to buffer to disk, e.g. multipart file uploads with an image attached).
RUN mkdir -p /var/lib/nginx/tmp/client_body /var/lib/nginx/tmp/proxy \
             /var/lib/nginx/tmp/fastcgi /var/lib/nginx/tmp/uwsgi /var/lib/nginx/tmp/scgi \
             /run/nginx \
    && chown -R www-data:www-data /var/lib/nginx /run/nginx

# DO App Platform sets PORT at runtime and expects the app to listen on it;
# 8080 is the documented default and what we fall back to locally.
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
