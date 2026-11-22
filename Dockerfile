ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-apache

# System libraries and PHP extensions Bioly needs
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
        libsqlite3-dev libzip-dev libicu-dev unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd pdo_sqlite zip intl \
    && rm -rf /var/lib/apt/lists/*

# Apache: rewrite for routes, headers for security headers
RUN a2enmod rewrite headers

# Serve only the public folder, like on a real host
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && printf 'ServerName localhost\n<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
       > /etc/apache2/conf-available/bioly.conf \
    && a2enconf bioly

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-bioly.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/entrypoint.sh /usr/local/bin/bioly-entrypoint
RUN chmod +x /usr/local/bin/bioly-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["bioly-entrypoint"]
CMD ["apache2-foreground"]
