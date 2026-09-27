# syntax=docker/dockerfile:1
#
# API de AgendaUno (Laravel) para producción: PHP-FPM. La misma imagen corre la API,
# el worker de colas y el scheduler (cambia el comando en docker-compose.yml).
# Contexto de build: la raíz del repositorio.

FROM php:8.3-fpm-alpine AS base

# pdo_mysql (control plane y una BD por estudio), pcntl (el worker termina limpio),
# opcache. Redis va por predis (PHP puro). mysql-client: respaldos con mysqldump.
# su-exec: el worker y el scheduler corren como www-data.
RUN apk add --no-cache mysql-client su-exec \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl opcache

COPY infra/produccion/php.ini /usr/local/etc/php/conf.d/zz-agendauno.ini

WORKDIR /var/www/html

# --- Dependencias de Composer (sin las de desarrollo) ---
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY apps/api/ ./
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi

# --- Imagen final ---
FROM base
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --chmod=0755 infra/produccion/api-entrypoint.sh /usr/local/bin/agendauno-entrypoint

ENTRYPOINT ["agendauno-entrypoint"]
CMD ["php-fpm"]
