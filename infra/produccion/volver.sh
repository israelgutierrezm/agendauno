#!/usr/bin/env sh
# Regresa AgendaUno a una versión anterior ya construida en este servidor (sin
# reconstruir). Guía: docs/DESPLIEGUE.md.
#
#   ./volver.sh                # la versión anterior a la actual
#   ./volver.sh 3f4b7c9        # una versión concreta (ver .historial-versiones)
#   ./volver.sh --solo landing-turnouno [VERSION]   # un componente sin estado (abajo)
#
# Sin --solo regresa la plataforma (api, worker, scheduler) y la aplicación web; las
# landings se quedan como están. Con --solo (web, landing-agendauno o landing-turnouno;
# ADR 0112) solo cambia ese contenedor, sin mantenimiento.
#
# Solo cambia el código en marcha. Las migraciones de AgendaUno se escriben para que
# la versión anterior siga funcionando con el esquema nuevo (primero se agrega,
# después se quita en otra versión). Si una actualización cambió datos de forma
# incompatible, además restaura el respaldo tomado justo antes de actualizar:
#   docker compose --env-file web.env exec -u www-data api php artisan agendauno:restaurar-plataforma --force
#   docker compose --env-file web.env exec -u www-data api php artisan agendauno:restaurar-estudio {slug} --force
#
# Como al actualizar: pone mantenimiento, deja terminar la cola y el programador,
# cambia de versión y solo reabre si la versión atiende (una petición real por nginx
# con la base y la caché bien, el programador de esa versión latiendo y su worker
# arrancado; en mantenimiento no toma trabajos). Si no, se queda en mantenimiento y
# sale con error. Ya abierta, confirma que la cola procesa.
set -eu

principal() {
  cd "$(dirname "$0")"
  . ./versiones.sh
  # La versión en marcha de cada componente y los archivos de compose de web.env.
  cargar_versiones
  COMPOSE="docker compose --env-file web.env"

  if [ "${1:-}" = "--solo" ]; then
    if ! es_componente_solo "${2:-}"; then
      echo "Con --solo se regresa uno de: $COMPONENTES_SOLOS."
      exit 1
    fi
    volver_componente "$2" "${3:-}"
    return
  fi

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

  for imagen in "agendauno-api:$DESTINO" "agendauno-web:$DESTINO"; do
    if ! docker image inspect "$imagen" >/dev/null 2>&1; then
      echo "La imagen $imagen ya no está en este servidor."
      echo "Reconstrúyela con: git checkout $DESTINO && VERSION=$DESTINO VERSION_WEB=$DESTINO ./compose.sh build api web"
      exit 1
    fi
  done
  # La aplicación web regresa con la plataforma; las landings, no.
  VERSION_WEB="$DESTINO"
  export VERSION_WEB

  echo "==> Volviendo de $ACTUAL a $DESTINO"
  echo "==> Mantenimiento y sin tareas en curso"
  $COMPOSE exec -T -u www-data api php artisan down --retry=60 --secret="$SECRETO" || true
  $COMPOSE stop worker scheduler || true
  $COMPOSE exec -T -u www-data api php artisan schedule:clear-cache >/dev/null 2>&1 || true

  echo "==> Levantando $DESTINO (sigue en mantenimiento)"
  # Señales en blanco: solo cuentan las del programador y el worker que arrancan ahora.
  for llave in operacion:latido:programador operacion:latido:cola operacion:arranque:cola; do
    VERSION="$DESTINO" $COMPOSE run --rm api php artisan cache:forget "$llave" >/dev/null
  done
  VERSION="$DESTINO" $COMPOSE up -d --remove-orphans --no-build api worker scheduler web

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

  VERSION="$DESTINO" $COMPOSE exec -T -u www-data api php artisan up
  echo "$DESTINO" > .version-actual
  echo "$DESTINO" > "$(archivo_version web)"
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $ACTUAL -> $DESTINO (volver)" >> .historial-versiones

  echo "==> Confirmando que la cola procesa"
  intentos=0
  until VERSION="$DESTINO" $COMPOSE exec -T -u www-data api php artisan agendauno:latido --verificar=cola --minutos=2 >/dev/null 2>&1; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 18 ]; then
      echo "!! $DESTINO está abierta, pero la cola no procesó trabajos en 3 minutos."
      echo "   Revisa: VERSION=$DESTINO $COMPOSE logs --tail=100 worker scheduler"
      exit 2
    fi
    sleep 10
  done
  echo "==> Listo: $DESTINO en marcha y atendiendo. Revisa lo demás con:"
  echo "   VERSION=$DESTINO $COMPOSE exec -u www-data api php artisan agendauno:verificar-produccion"
}

# Regresa solo un componente sin estado (ADR 0112) a una versión ya construida: por
# omisión, la de la que se vino a la actual según el historial.
volver_componente() {
  componente="$1"
  variable="$(variable_version "$componente")"
  actual="$(version_de "$componente")"
  destino="$2"
  if [ -z "$destino" ]; then
    destino="$(awk -v c="$componente:" -v actual="$actual" \
      '$2 == c && $5 == actual && $0 !~ /fallid/ { anterior = $3 } END { print anterior }' \
      .historial-versiones 2>/dev/null || true)"
  fi
  if [ -z "$destino" ]; then
    echo "No sé a qué versión volver $componente: indícala (./volver.sh --solo $componente VERSION). Historial:"
    grep " $componente: " .historial-versiones 2>/dev/null || echo "  (vacío)"
    exit 1
  fi
  if ! docker image inspect "agendauno-$componente:$destino" >/dev/null 2>&1; then
    echo "La imagen agendauno-$componente:$destino ya no está en este servidor."
    echo "Publícala de nuevo con: ./actualizar.sh --solo $componente $destino"
    exit 1
  fi

  echo "==> Volviendo $componente de $actual a $destino"
  export "$variable=$destino"
  $COMPOSE up -d --no-deps --no-build "$componente"
  if ! componente_atiende "$componente"; then
    echo "!! $componente $destino no atendió en 2 minutos. Revisa: ./compose.sh logs --tail=100 $componente web"
    anotar "$componente: $actual -> $destino (volver fallido)"
    exit 1
  fi
  echo "$destino" > "$(archivo_version "$componente")"
  anotar "$componente: $actual -> $destino (volver)"
  echo "==> Listo: $componente $destino en marcha."
}

# Una petición real por nginx y PHP-FPM, con la galleta que deja pasar el
# mantenimiento: base y caché bien, programador de esta versión latiendo y su worker
# arrancado (?estricto=1).
disponible() {
  galleta="$(curl -s -o /dev/null -D - -H "Host: $DOMINIO" "http://127.0.0.1:8080/$SECRETO" \
    | tr -d '\r' | sed -n 's/^[Ss]et-[Cc]ookie: *\(laravel_maintenance=[^;]*\).*/\1/p' | head -n 1)"
  [ -n "$galleta" ] || return 1
  curl -fsS -o /dev/null -H "Host: $DOMINIO" -H "Cookie: $galleta" "http://127.0.0.1:8080/api/v1/health?estricto=1"
}

principal "$@"
exit
