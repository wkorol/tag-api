FROM tag-base AS tag-api

RUN apt-get update && apt-get install -y \
    git unzip curl libicu-dev libpq-dev libzip-dev \
 && docker-php-ext-install intl opcache zip pdo pdo_pgsql \
 && curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin --filename=composer \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

