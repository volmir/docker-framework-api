FROM php:8.4-fpm-alpine AS base

# mbstring, sodium, Argon2, OPcache and the PHPUnit extensions are already present.
RUN apk add --no-cache fcgi tzdata \
    && docker-php-ext-install pdo_mysql \
    && addgroup -S -g 1000 app \
    && adduser -S -D -H -u 1000 -G app app \
    && mkdir -p /var/www/app \
    && chown app:app /var/www/app

COPY .docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY .docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 .docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

WORKDIR /var/www/app
ENV COMPOSER_HOME=/tmp/composer
USER app

FROM base AS deps
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --chown=app:app composer.json composer.lock ./
COPY --chown=app:app src/ ./src/
RUN composer install --no-dev --classmap-authoritative --no-interaction --no-progress

FROM base AS prod
COPY --chown=app:app . .
COPY --from=deps --chown=app:app /var/www/app/vendor ./vendor

FROM base AS dev
USER root
RUN apk add --no-cache unzip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY .docker/php/php-dev.ini /usr/local/etc/php/conf.d/zzz-dev.ini
USER app

FROM dev AS test
COPY --chown=app:app composer.json composer.lock ./
COPY --chown=app:app src/ ./src/
RUN composer install --no-interaction --no-progress
COPY --chown=app:app . .
CMD ["sh", "bin/test"]
