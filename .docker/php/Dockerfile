# Compile PHP extensions and install production Composer dependencies.
FROM php:8.4.27-fpm-alpine3.24 AS builder

ARG TIMEZONE=UTC
ENV TZ=${TIMEZONE}

# Runtime libraries are also installed in the final stage.
RUN apk add --no-cache \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
        libxslt \
        oniguruma \
        tzdata \
    && apk add --no-cache --virtual .php-build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        libxslt-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pdo_mysql \
        xsl \
        zip \
    && apk del .php-build-deps \
    && cp "/usr/share/zoneinfo/${TZ}" /etc/localtime \
    && echo "${TZ}" > /etc/timezone

# Use the Composer binary from its official image.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/application

# Copy dependency metadata and application classes first for better layer caching.
COPY composer.json composer.lock ./
COPY src/ ./src/

# Production dependencies only; optimize the generated Composer autoloader.
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Copy the rest of the application after installing dependencies.
COPY . .


# Runtime image: PHP-FPM, required shared libraries, extensions, and application.
FROM php:8.4.27-fpm-alpine3.24 AS runtime

ARG TIMEZONE=UTC
ENV TZ=${TIMEZONE}

RUN apk add --no-cache \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
        libxslt \
        oniguruma \
        tzdata \
    && cp "/usr/share/zoneinfo/${TZ}" /etc/localtime \
    && echo "${TZ}" > /etc/timezone

# Copy the extensions built against the exact same PHP and Alpine base image.
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Configure PHP and OPcache for an immutable production image.
COPY .docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
# Route PHP and PHP-FPM logs to container streams for the Compose logging driver.
RUN printf '%s\n' \
        'opcache.enable=1' \
        'opcache.validate_timestamps=0' \
        'opcache.memory_consumption=192' \
        'opcache.max_accelerated_files=10000' \
        > /usr/local/etc/php/conf.d/zz-opcache.ini \
    && printf '%s\n' \
        'memory_limit=256M' \
        'log_errors=On' \
        'error_log=/proc/self/fd/2' \
        'display_errors=Off' \
        > /usr/local/etc/php/conf.d/zz-logging.ini \
    && printf '%s\n' \
        '[global]' \
        'error_log = /proc/self/fd/2' \
        '[www]' \
        'access.log = /proc/self/fd/1' \
        'catch_workers_output = yes' \
        'decorate_workers_output = no' \
        'pm.max_children = 3' \
        > /usr/local/etc/php-fpm.d/zz-logging.conf \
    && sed -i 's|^;pid = run/php-fpm.pid|pid = /tmp/php-fpm.pid|' /usr/local/etc/php-fpm.conf \
    && addgroup -S -g 1000 appuser \
    && adduser -S -D -H -u 1000 -G appuser appuser \
    && mkdir -p /var/www/application /var/www/application/logs \
    && chown -R appuser:appuser /var/www/application

WORKDIR /var/www/application

# Run FPM and the application as an unprivileged user.
USER appuser

EXPOSE 9000

CMD ["php-fpm", "-F"]
