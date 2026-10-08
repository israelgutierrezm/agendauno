#!/bin/sh
# Arranque de la API, el worker y el scheduler (misma imagen).
# - Crea la estructura de storage (el volumen llega vacío la primera vez).
# - Cachea configuración, rutas y vistas con las variables de este contenedor.
# - php-fpm arranca como root y atiende con www-data; cualquier otro comando
#   (worker, scheduler, artisan) corre directamente como www-data.
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "Falta APP_KEY: genérala una sola vez (ver docs/DESPLIEGUE.md) y no la cambies." >&2
    exit 1
fi

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs storage/tenants storage/app/respaldos-temp

php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache
# Carpeta de trabajo de los respaldos (volcados de las bases): de www-data y solo
# suya. Si la creara primero un comando con `docker compose exec` (root), el
# programador ya no podría escribir ahí y los respaldos nocturnos fallarían.
chmod 700 storage/app/respaldos-temp

if [ "$1" = "php-fpm" ]; then
    exec "$@"
fi
# El programador, al arrancar, suelta los candados de «una a la vez» que dejó una
# caída (OOM, reinicio del servidor): si no, esas tareas se saltan hasta 24 horas.
if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "schedule:work" ]; then
    su-exec www-data php artisan schedule:clear-cache || true
fi
exec su-exec www-data "$@"
