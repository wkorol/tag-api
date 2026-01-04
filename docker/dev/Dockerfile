FROM tag-base AS tag-api

ENV TZ=Europe/Warsaw

RUN apt-get update && apt-get install -y \
    tzdata git unzip curl libicu-dev libpq-dev libzip-dev \
 && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
 && echo $TZ > /etc/timezone \
 && docker-php-ext-install intl opcache zip pdo pdo_pgsql \
 && curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin --filename=composer \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite
