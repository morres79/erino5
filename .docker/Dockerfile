FROM php:8.1-apache

RUN docker-php-ext-install \
    mysqli \
    pdo_mysql

RUN a2enmod rewrite headers

RUN pecl install xdebug-3.4.2 \
    && docker-php-ext-enable xdebug

COPY .docker/xdebug.ini \
    /usr/local/etc/php/conf.d/99-xdebug.ini

WORKDIR /var/www/html