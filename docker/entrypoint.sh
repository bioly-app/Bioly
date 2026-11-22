#!/bin/sh
set -e

# Install dependencies on first start
if [ -f composer.json ] && [ ! -d vendor ]; then
    composer install --no-interaction
fi

# Writable data folder
mkdir -p data/cache data/logs data/uploads data/updates data/backups
chown -R www-data:www-data data

if [ "$APP_ENV" = "dev" ]; then
    if [ ! -f data/app.sqlite ]; then
        # First start: admin user and demo content, installer is skipped
        su -s /bin/sh www-data -c "php bin/seed.php"
    else
        # Later starts: apply new migrations automatically
        su -s /bin/sh www-data -c "php bin/migrate.php"
    fi
fi

exec docker-php-entrypoint "$@"
