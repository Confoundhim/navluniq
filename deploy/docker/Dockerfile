# NavlunIQ yerel çalıştırma imajı (docker compose). PHP 8.4 + gerekli eklentiler + Composer + Node 20.
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates gnupg default-mysql-client \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath gd intl zip exif opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Canlıdaki gibi: 12 MB belge yüklemesi, 256 MB bellek.
RUN printf 'upload_max_filesize = 12M\npost_max_size = 32M\nmemory_limit = 256M\nmax_execution_time = 120\n' > /usr/local/etc/php/conf.d/99-navluniq.ini

WORKDIR /var/www/navluniq
