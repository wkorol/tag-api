FROM php:8.4-apache-bookworm

RUN apt-get update && apt-get install -y \
    git unzip curl libicu-dev libpq-dev libzip-dev \
 && docker-php-ext-install intl opcache zip pdo pdo_pgsql \
 && curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin --filename=composer \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

WORKDIR /home/www

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

COPY . .
RUN touch /home/www/.env
RUN composer dump-autoload --no-dev --optimize

RUN mkdir -p /home/www/var/cache /home/www/var/log \
    && chown -R www-data:www-data /home/www/var \
    && chmod -R 775 /home/www/var

COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh


COPY docker/prod/vhost.conf /etc/apache2/sites-enabled/000-default.conf

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
