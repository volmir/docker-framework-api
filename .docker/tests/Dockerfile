FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y libxslt1-dev libzip-dev \
    && docker-php-ext-install xsl \
    && docker-php-ext-install intl \
    && docker-php-ext-install zip \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN docker-php-ext-install \
    pdo pdo_mysql
