FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libicu-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli intl \
    && apt-get clean

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80