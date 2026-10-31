FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libonig-dev libxml2-dev libxslt1-dev libzip-dev \
    && docker-php-ext-install dom intl mbstring pdo_mysql pdo_sqlite xsl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
