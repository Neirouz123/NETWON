FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-gd

FROM php:8.3-apache-bookworm

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl opcache pdo_pgsql gd zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/symfony/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/symfony/php.ini /usr/local/etc/php/conf.d/zz-production.ini
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY docker/symfony/entrypoint.sh /usr/local/bin/symfony-entrypoint
RUN chmod +x /usr/local/bin/symfony-entrypoint

RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

EXPOSE 80
ENTRYPOINT ["symfony-entrypoint"]
CMD ["apache2-foreground"]
