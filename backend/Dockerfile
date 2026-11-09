FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev unzip \
    && docker-php-ext-install pdo_pgsql pgsql mbstring curl opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install \
      --no-dev \
      --prefer-dist \
      --no-interaction \
      --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/render-entrypoint.sh /usr/local/bin/render-entrypoint
RUN chmod +x /usr/local/bin/render-entrypoint

EXPOSE 10000

CMD ["/usr/local/bin/render-entrypoint"]
