FROM php:8.1-apache

# OpenCart 3.0.5.0 requires PHP 8.1+ (system/startup.php hard-exits below that).
# --- OpenCart 3.0.x runtime dependencies -------------------------------------
# Required PHP extensions (per install/cli_install.php check_requirements):
#   mysqli, gd, curl, openssl (bundled), zlib (bundled)
# Plus zip/intl/mbstring for OCMOD packaging, i18n and the spintax engine.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libcurl4-openssl-dev libzip-dev libicu-dev libonig-dev libxml2-dev \
        unzip default-mysql-client; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd mysqli curl zip intl mbstring opcache; \
    rm -rf /var/lib/apt/lists/*

# OpenCart SEO URLs + security headers need mod_rewrite/headers,
# and .htaccess overrides must be permitted on the docroot.
RUN a2enmod rewrite headers; \
    sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

COPY php-oc.ini /usr/local/etc/php/conf.d/zz-opencart.ini

# Docroot is the mounted opencart/upload volume (see docker-compose.yml).
