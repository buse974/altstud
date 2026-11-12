FROM php:8-apache

# Claude Code (CLI) : utilisé par le générateur de CV vd/generer.php
RUN apt-get update \
    && apt-get install -y --no-install-recommends nodejs npm \
    && npm install -g @anthropic-ai/claude-code \
    && npm cache clean --force \
    && apt-get purge -y npm && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules used by .htaccess
RUN a2enmod rewrite headers expires deflate

# Allow .htaccess to override config (RewriteRule, Header, FilesMatch, etc.)
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Copy all site files
COPY . /var/www/html/

# CV générés (à monter en volume pour qu'ils survivent aux redéploiements)
RUN mkdir -p /var/www/html/vd/gen && chown www-data:www-data /var/www/html/vd/gen

EXPOSE 80
