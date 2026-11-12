# Dépendances PHP (SDK Claude pour le générateur de CV vd/generer.php)
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs

FROM php:8-apache

# Enable Apache modules used by .htaccess
RUN a2enmod rewrite headers expires deflate

# Allow .htaccess to override config (RewriteRule, Header, FilesMatch, etc.)
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Copy all site files
COPY . /var/www/html/
COPY --from=deps /app/php-vendor /var/www/html/php-vendor

# CV générés (à monter en volume pour qu'ils survivent aux redéploiements)
RUN mkdir -p /var/www/html/vd/gen && chown www-data:www-data /var/www/html/vd/gen

EXPOSE 80
