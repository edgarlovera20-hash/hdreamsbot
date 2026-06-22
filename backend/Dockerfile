FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        curl-dev \
        oniguruma-dev \
        dcron \
    && docker-php-ext-install \
        mysqli \
        pdo_mysql \
        mbstring

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/hdreams-backend

COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && mkdir -p storage/uploads storage/logs

EXPOSE 9000
CMD ["php-fpm"]
