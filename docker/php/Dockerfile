# Development image only. Railway builds the application separately with Railpack.
# Node is copied from the official image so Laravel Wayfinder can call PHP during Vite builds.
FROM node:22-bookworm-slim AS node
FROM composer:2 AS composer
FROM php:8.3-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip ca-certificates \
        libpq-dev libonig-dev libzip-dev libicu-dev libsqlite3-dev \
    && docker-php-ext-install -j2 \
        bcmath intl mbstring pcntl pdo_pgsql pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && php -r 'foreach (["pdo_pgsql", "pdo_sqlite", "mbstring", "intl", "zip", "pcntl"] as $ext) { if (!extension_loaded($ext)) exit(1); }' \
    && node --version && npm --version && composer --version

WORKDIR /var/www/html

EXPOSE 8000 5173

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
