ARG PHP_VERSION=8.5

FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:${PHP_VERSION}-apache

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Point Apache document root to public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot ${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/html>|<Directory ${APACHE_DOCUMENT_ROOT}>|g' /etc/apache2/apache2.conf

# Copy application files and production Composer dependencies
COPY . /var/www/html/
COPY --from=dependencies /app/vendor /var/www/html/vendor

# Ensure runtime and upload directories can be written by Apache
RUN mkdir -p /var/www/html/runtime/cache /var/www/html/runtime/logs \
    /var/www/html/runtime/session /var/www/html/public/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/runtime /var/www/html/public/uploads

EXPOSE 80