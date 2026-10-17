FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libonig-dev libsqlite3-dev libxml2-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring intl pdo_sqlite zip bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /package
