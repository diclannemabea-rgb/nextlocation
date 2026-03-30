FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libicu-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli intl \
    && apt-get clean

RUN echo "display_errors = On" >> /usr/local/etc/php/php.ini \
    && echo "error_reporting = E_ALL" >> /usr/local/etc/php/php.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/php.ini

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80