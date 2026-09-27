#!/usr/bin/env sh
# Regresa AgendaUno a una versión anterior ya construida en este servidor (sin
# reconstruir). Guía: docs/DESPLIEGUE.md.
#
#   ./volver.sh                # la versión anterior a la actual
#   ./volver.sh 3f4b7c9        # una versión concreta (ver .historial-versiones)
#
# Solo cambia el código en marcha. Las migraciones de AgendaUno se escriben para que
# la versión anterior siga funcionando con el esquema nuevo (primero se agrega,
# después se quita en otra versión). Si una actualización cambió datos de forma
# incompatible, además restaura el respaldo tomado justo antes de actualizar:
#   docker compose --env-file web.env exec api php artisan turnouno:restaurar-plataforma --force
#   docker compose --env-file web.env exec api php artisan turnouno:restaurar-estudio {slug} --force
set -eu

cd "$(dirname "$0")"
COMPOSE="docker compose --env-file web.env"
ACTUAL="$(cat .version-actual 2>/dev/null || echo latest)"

if [ "${1:-}" != "" ]; then
  DESTINO="$1"
else
  # La versión de la que se vino a la actual, según el historial.
  DESTINO="$(awk -v actual="$ACTUAL" '$4 == actual { anterior = $2 } END { print anterior }' .historial-versiones 2>/dev/null || true)"
fi

if [ -z "$DESTINO" ]; then
  echo "No sé a qué versión volver: indícala (./volver.sh VERSION). Historial:"
  cat .historial-versiones 2>/dev/null || echo "  (vacío)"
  exit 1
fi

if ! docker image inspect "agendauno-api:$DESTINO" >/dev/null 2>&1; then
  echo "La imagen agendauno-api:$DESTINO ya no está en este servidor."
  echo "Reconstrúyela con: git checkout $DESTINO && VERSION=$DESTINO $COMPOSE build"
  exit 1
fi

echo "==> Volviendo de $ACTUAL a $DESTINO"
VERSION="$DESTINO" $COMPOSE up -d --remove-orphans
VERSION="$DESTINO" $COMPOSE exec -T api php artisan up || true

echo "$DESTINO" > .version-actual
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $ACTUAL -> $DESTINO (volver)" >> .historial-versiones
echo "==> Listo: $DESTINO en marcha. Comprueba con:"
echo "   VERSION=$DESTINO $COMPOSE exec api php artisan turnouno:verificar-produccion"
