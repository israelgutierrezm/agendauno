#!/usr/bin/env sh
# Actualiza AgendaUno en el servidor a una versión nueva (por defecto, lo último de
# main) sin perder el camino de regreso. Guía: docs/DESPLIEGUE.md.
#
#   ./actualizar.sh            # lo último de origin/main
#   ./actualizar.sh v1.4.0     # una etiqueta o commit
#
# Pasos: construye la versión nueva (imágenes etiquetadas con su commit), respalda la
# plataforma y los negocios ANTES de migrar, pone mantenimiento, migra, levanta la
# versión nueva, verifica y la anota en .historial-versiones. Si algo falla después
# de migrar, ./volver.sh regresa a la versión anterior (y, si hiciera falta, el
# respaldo recién tomado permite restaurar los datos).
set -eu

cd "$(dirname "$0")"
COMPOSE="docker compose --env-file web.env"
REF="${1:-origin/main}"

ANTERIOR="$(cat .version-actual 2>/dev/null || echo latest)"

echo "==> Trayendo el código ($REF)"
git fetch --tags origin
git checkout --quiet "$REF"
VERSION="$(git rev-parse --short HEAD)"
export VERSION
echo "    versión nueva: $VERSION (actual: $ANTERIOR)"

echo "==> Construyendo imágenes $VERSION"
$COMPOSE build

EN_MARCHA="$(VERSION="$ANTERIOR" $COMPOSE ps -q api 2>/dev/null || true)"
if [ -n "$EN_MARCHA" ]; then
  # Un respaldo fallido detiene la actualización (set -e): no se migra sin punto
  # de regreso.
  echo "==> Respaldando antes de migrar (con la versión en marcha)"
  if [ "${SIN_RESPALDO_PLATAFORMA:-0}" = "1" ]; then
    # Solo la primera vez: la versión en marcha aún no tiene este comando; respalda
    # MySQL con la herramienta del proveedor antes de usar esta opción.
    echo "    (se omite el respaldo de la plataforma: SIN_RESPALDO_PLATAFORMA=1)"
  else
    VERSION="$ANTERIOR" $COMPOSE exec -T api php artisan turnouno:respaldar-plataforma
  fi
  VERSION="$ANTERIOR" $COMPOSE exec -T api php artisan turnouno:respaldar-estudios

  echo "==> Modo mantenimiento"
  VERSION="$ANTERIOR" $COMPOSE exec -T api php artisan down --retry=60 || true
else
  echo "==> Primera instalación: no hay versión en marcha que respaldar"
fi

echo "==> Migraciones (plataforma y cada negocio)"
if ! $COMPOSE run --rm api php artisan migrate --force \
  || ! $COMPOSE run --rm api php artisan turnouno:migrar-estudios --force; then
  echo "!! Falló una migración. La versión anterior ($ANTERIOR) sigue en marcha en mantenimiento."
  echo "   Revisa el error; para salir de mantenimiento sin actualizar:"
  echo "   VERSION=$ANTERIOR $COMPOSE exec api php artisan up"
  exit 1
fi

echo "==> Levantando $VERSION"
$COMPOSE up -d --remove-orphans
$COMPOSE exec -T api php artisan up

echo "==> Verificando"
sleep 5
if ! $COMPOSE exec -T api php artisan turnouno:verificar-produccion; then
  echo "!! La verificación encontró pendientes (arriba). Si la versión nueva no funciona:"
  echo "   ./volver.sh $ANTERIOR"
fi

echo "$VERSION" > .version-actual
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $ANTERIOR -> $VERSION" >> .historial-versiones
echo "==> Listo: $VERSION en marcha (la anterior era $ANTERIOR; para regresar: ./volver.sh)"
