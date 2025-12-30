#!/bin/sh
set -e

mkdir -p /home/www/var/cache /home/www/var/log
chown -R www-data:www-data /home/www/var
chmod -R 775 /home/www/var

exec "$@"
