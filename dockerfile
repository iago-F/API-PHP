FROM php:8.3-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev libsqlite3-dev \
    && docker-php-ext-install curl pdo pdo_sqlite \
    && a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p /var/www/html/database && chown -R www-data:www-data /var/www/html/database
