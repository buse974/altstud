FROM php:8-apache

# Enable mod_rewrite for .htaccess
RUN a2enmod rewrite

# Copy all site files
COPY . /var/www/html/

EXPOSE 80
