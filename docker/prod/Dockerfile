FROM php:8.4-apache-bookworm

RUN apt-get update && apt-get install -y \
    git unzip curl libicu-dev libpq-dev libzip-dev \
 && docker-php-ext-install intl opcache zip pdo pdo_pgsql \
 && curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin --filename=composer \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

WORKDIR /home/www

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize

COPY docker/prod/vhost.conf /etc/apache2/sites-enabled/000-default.conf

