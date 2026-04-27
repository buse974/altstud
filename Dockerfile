FROM php:8-apache

# Enable Apache modules used by .htaccess
RUN a2enmod rewrite headers expires deflate

# Allow .htaccess to override config (RewriteRule, Header, FilesMatch, etc.)
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Copy all site files
COPY . /var/www/html/

EXPOSE 80
