FROM php:8.4.27-apache

RUN apt-get update
RUN apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev unzip

RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install -j$(nproc) gd pdo pdo_mysql opcache

RUN mkdir -p mkdir -p /var/lib/php/soap_cache && mkdir -p /var/lib/php/composer
RUN chown -R www-data:www-data /var/lib/php/

# worckaround while https://github.com/chartjs/Chart.js/issues/11478 is not fixed
RUN mkdir -p /var/www/html/vendor/chartjs/
RUN curl -o /var/www/html/vendor/chartjs/chart.js https://cdn.jsdelivr.net/npm/chart.js

ENV PHP_CACHE=1
ENV COMPOSER_HOME=/var/lib/php/composer

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer/composer:latest-bin /composer /usr/bin/composer
COPY ./server_conf/moncycleapp_apache.conf /etc/apache2/conf-enabled/
COPY ./www_data /var/www/html/
COPY ./www_data/config.docker.php /var/www/html/config.php

RUN chown -R www-data:www-data /var/www/html

USER www-data

RUN composer update

USER root

COPY ./server_conf/moncycleapp_php.ini $PHP_INI_DIR/conf.d
