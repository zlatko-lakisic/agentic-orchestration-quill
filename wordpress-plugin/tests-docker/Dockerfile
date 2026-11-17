FROM php:8.2-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip default-mysql-client libzip-dev libpng-dev libjpeg-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install mysqli zip gd exif \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
