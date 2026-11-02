#!/bin/sh
if [ "$1" = php-fpm ]; then
    if [ -f /var/www/app/vendor/autoload.php ]; then
        php /var/www/app/bin/console env:check || exit 1
    else
        echo 'run: make install' >&2
    fi
fi
exec "$@"
