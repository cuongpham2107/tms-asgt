# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.5

# --- Stage 1: Composer Vendor for Theme Dependencies ---
FROM composer:2 AS composer-builder
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-req=php+ --ignore-platform-req=ext-*

# --- Stage 2: Build Frontend Assets (Vite & Filament) ---
FROM node:22-alpine AS assets-builder
WORKDIR /app
COPY package.json package-lock.json* bun.lock* ./
RUN npm install
COPY --from=composer-builder /app/vendor ./vendor
COPY . .
RUN npm run build

# --- Stage 3: Production Application Container with FrankenPHP ---
FROM dunglas/frankenphp:1-php${PHP_VERSION}-alpine

# Install system utilities (PostgreSQL client for database backups)
RUN apk add --no-cache postgresql16-client

# Install essential PHP extensions for Laravel, PostgreSQL, Filament, and Queue/Reverb
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    gd \
    zip \
    intl \
    bcmath \
    pcntl \
    opcache \
    redis

# Copy Composer binary
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application configuration
COPY .docker/php/php.ini $PHP_INI_DIR/conf.d/custom.ini
COPY .docker/frankenphp/Caddyfile /etc/caddy/Caddyfile

# Copy application source code
COPY . .
COPY --from=assets-builder /app/public/build ./public/build

# Install PHP dependencies without dev packages
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-req=php+

# Set correct permissions for Laravel runtime directories
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Expose HTTP, HTTPS and HTTP/3 ports
EXPOSE 80 443 443/udp

# Default command: Start Octane with FrankenPHP
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80", "--admin-port=2019", "--caddyfile=/etc/caddy/Caddyfile", "--max-requests=500"]
