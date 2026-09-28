# Testumgebung fuer die Extension n9c_monitor - ein Image je PHP-Version.
# TYPO3 selbst wird erst im Container von setup-typo3.sh installiert.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip wget libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" intl zip gd opcache \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers expires \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && printf 'memory_limit=512M\nmax_execution_time=240\nmax_input_vars=1500\n' > /usr/local/etc/php/conf.d/typo3.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY setup-typo3.sh /usr/local/bin/setup-typo3.sh
RUN chmod 755 /usr/local/bin/setup-typo3.sh
ENV APACHE_DOCUMENT_ROOT=/var/www/html
