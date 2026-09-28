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
#
# Como al actualizar: pone mantenimiento, deja terminar la cola y el programador,
# cambia de versión y solo reabre si la versión atiende (una petición real por nginx
# con la base, la caché, la cola y el programador bien). Si no, se queda en
# mantenimiento y sale con error.
set -eu

principal() {
  cd "$(dirname "$0")"
  COMPOSE="docker compose --env-file web.env"
  ACTUAL="$(cat .version-actual 2>/dev/null || echo latest)"
  DOMINIO="$(sed -n 's/^DOMINIO=//p' web.env | tr -d '\r' | head -n 1)"
  SECRETO="api/revision-$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"

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
  echo "==> Mantenimiento y sin tareas en curso"
  $COMPOSE exec -T api php artisan down --retry=60 --secret="$SECRETO" || true
  $COMPOSE stop worker scheduler || true
  $COMPOSE exec -T api php artisan schedule:clear-cache >/dev/null 2>&1 || true

  echo "==> Levantando $DESTINO (sigue en mantenimiento)"
  VERSION="$DESTINO" $COMPOSE run --rm api php artisan cache:forget operacion:latido:programador >/dev/null
  VERSION="$DESTINO" $COMPOSE run --rm api php artisan cache:forget operacion:latido:cola >/dev/null
  VERSION="$DESTINO" $COMPOSE up -d --remove-orphans

  echo "==> Comprobando que $DESTINO atiende antes de abrir"
  intentos=0
  until disponible; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 30 ]; then
      echo "!! $DESTINO no quedó lista en 5 minutos. Sigue en MANTENIMIENTO, sin abrir al público."
      echo "   Revisa: VERSION=$DESTINO $COMPOSE logs --tail=100 api worker scheduler"
      echo "   Si el esquema no es compatible con $DESTINO, restaura los respaldos (docs/DESPLIEGUE.md → Restaurar)."
      echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $ACTUAL -> $DESTINO (volver fallido: no se abrió)" >> .historial-versiones
      exit 1
    fi
    sleep 10
  done

  VERSION="$DESTINO" $COMPOSE exec -T api php artisan up
  echo "$DESTINO" > .version-actual
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $ACTUAL -> $DESTINO (volver)" >> .historial-versiones
  echo "==> Listo: $DESTINO en marcha y atendiendo. Revisa lo demás con:"
  echo "   VERSION=$DESTINO $COMPOSE exec api php artisan turnouno:verificar-produccion"
}

# Una petición real por nginx y PHP-FPM, con la galleta que deja pasar el
# mantenimiento: base, caché, cola y programador bien (?estricto=1).
disponible() {
  galleta="$(curl -s -o /dev/null -D - -H "Host: $DOMINIO" "http://127.0.0.1:8080/$SECRETO" \
    | tr -d '\r' | sed -n 's/^[Ss]et-[Cc]ookie: *\(laravel_maintenance=[^;]*\).*/\1/p' | head -n 1)"
  [ -n "$galleta" ] || return 1
  curl -fsS -o /dev/null -H "Host: $DOMINIO" -H "Cookie: $galleta" "http://127.0.0.1:8080/api/v1/health?estricto=1"
}

principal "$@"
exit
