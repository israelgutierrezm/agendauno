#!/usr/bin/env sh
# Actualiza AgendaUno en el servidor a una versión nueva (por defecto, lo último de
# main) sin perder el camino de regreso. Guía: docs/DESPLIEGUE.md.
#
#   ./actualizar.sh            # lo último de origin/main
#   ./actualizar.sh v1.4.0     # una etiqueta o commit
#   ./actualizar.sh --solo landing-turnouno [REF]   # un componente sin estado (abajo)
#
# Sin --solo publica todo: la plataforma (api, worker, scheduler), la aplicación web y
# las dos landings, con los pasos de abajo. Con --solo (web, landing-agendauno o
# landing-turnouno; ADR 0112) solo construye y cambia ese contenedor: no toca datos, así
# que no pone mantenimiento, ni respalda, ni migra; si la versión nueva no atiende, deja
# la anterior.
#
# 1. Construye la versión nueva (imágenes etiquetadas con su commit) sin tocar lo que
#    está en marcha.
# 2. Punto de corte: mantenimiento (no entran escrituras), la cola termina el trabajo
#    en curso y el programador espera a que acaben sus tareas (stop_grace_period en
#    docker-compose.yml). Recién entonces respalda la plataforma y cada negocio: el
#    respaldo trae todo lo aceptado. Si el respaldo falla, reabre lo anterior y no
#    migra.
# 3. Migra (plataforma y cada negocio) y levanta la versión nueva, todavía en
#    mantenimiento.
# 4. Solo la abre si atiende: base, caché, esquema al día, el programador nuevo
#    latiendo (su latido corre también en mantenimiento) y el worker nuevo en marcha
#    (en mantenimiento no toma trabajos: cuenta su señal de arranque con la versión),
#    más una petición real por nginx. Si no, se queda en mantenimiento, no se anota
#    como actual y sale con error.
# 5. Ya abierta, confirma que la cola procesa (el latido pasa por ella) y corre la
#    verificación completa: si falta algo para operar en producción, lo dice y sale
#    con error (no «Listo»).
#
# Nunca revierte la base sola: las migraciones se escriben para que la versión
# anterior siga funcionando con el esquema nuevo; si una no lo fuera, se restaura a
# mano con los respaldos recién tomados (docs/DESPLIEGUE.md → Restaurar).
set -eu

# Todo va dentro de una función: `git checkout` puede cambiar este mismo archivo
# mientras corre, y sh lo lee conforme avanza. Así se lee completo antes de empezar.
principal() {
  cd "$(dirname "$0")"
  . ./versiones.sh
  # La versión en marcha de cada componente y los archivos de compose de web.env.
  cargar_versiones
  COMPOSE="docker compose --env-file web.env"

  if [ "${1:-}" = "--solo" ]; then
    if ! es_componente_solo "${2:-}"; then
      echo "Con --solo se publica uno de: $COMPONENTES_SOLOS."
      echo "La API (con su cola y su programador) se publica con todo: ./actualizar.sh [REF]."
      exit 1
    fi
    actualizar_componente "$2" "${3:-origin/main}"
    return
  fi

  REF="${1:-origin/main}"
  ANTERIOR="$(cat .version-actual 2>/dev/null || echo latest)"
  DOMINIO="$(sed -n 's/^DOMINIO=//p' web.env | tr -d '\r' | head -n 1)"
  DOMINIO_TURNOUNO="$(sed -n 's/^DOMINIO_TURNOUNO=//p' web.env | tr -d '\r' | head -n 1)"
  # Ruta secreta para probar la versión nueva por HTTP mientras sigue en
  # mantenimiento (empieza con api/ para que nginx la pase a PHP).
  SECRETO="api/revision-$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"

  echo "==> Trayendo el código ($REF)"
  git fetch --tags origin
  git checkout --quiet "$REF"
  VERSION="$(git rev-parse --short HEAD)"
  # Todo con la misma versión: la plataforma, la aplicación web y las landings.
  VERSION_WEB="$VERSION"
  VERSION_LANDING_AGENDAUNO="$VERSION"
  VERSION_LANDING_TURNOUNO="$VERSION"
  export VERSION VERSION_WEB VERSION_LANDING_AGENDAUNO VERSION_LANDING_TURNOUNO
  echo "    versión nueva: $VERSION (actual: $ANTERIOR)"

  echo "==> Construyendo imágenes $VERSION"
  $COMPOSE build

  EN_MARCHA="$(VERSION="$ANTERIOR" $COMPOSE ps -q api 2>/dev/null || true)"
  if [ -n "$EN_MARCHA" ]; then
    echo "==> Punto de corte: mantenimiento y sin tareas en curso"
    VERSION="$ANTERIOR" $COMPOSE exec -T -u www-data api php artisan down --retry=60 --secret="$SECRETO"
    echo "    esperando a que la cola y el programador terminen lo que están haciendo…"
    VERSION="$ANTERIOR" $COMPOSE stop worker scheduler
    # Si alguna tarea se cortó al vencer la espera, su candado no debe dejarla
    # bloqueada 24 h.
    VERSION="$ANTERIOR" $COMPOSE exec -T -u www-data api php artisan schedule:clear-cache >/dev/null

    echo "==> Respaldando (sin escrituras en curso)"
    if ! respaldar; then
      echo "!! Falló el respaldo: no se migra sin punto de regreso."
      reabrir_anterior
      anotar "$ANTERIOR -> $VERSION (cancelada: falló el respaldo)"
      exit 1
    fi
  else
    echo "==> Primera instalación: no hay versión en marcha que respaldar"
    $COMPOSE run --rm --no-deps api php artisan down --retry=60 --secret="$SECRETO"
  fi

  echo "==> Migraciones (plataforma y cada negocio)"
  if ! $COMPOSE run --rm api php artisan migrate --force \
    || ! $COMPOSE run --rm api php artisan agendauno:migrar-estudios --force; then
    echo "!! Falló una migración. Todo sigue en MANTENIMIENTO, con la versión $ANTERIOR detenida a medias"
    echo "   (sin cola ni programador). Parte del esquema pudo haber cambiado:"
    echo "   - revisa el error y, si $ANTERIOR funciona con el esquema actual, reábrela:"
    echo "       VERSION=$ANTERIOR $COMPOSE start worker scheduler"
    echo "       VERSION=$ANTERIOR $COMPOSE exec -u www-data api php artisan up"
    echo "   - si no, restaura los respaldos recién tomados (docs/DESPLIEGUE.md → Restaurar)."
    anotar "$ANTERIOR -> $VERSION (fallida: migración)"
    exit 1
  fi

  echo "==> Levantando $VERSION (sigue en mantenimiento)"
  # Señales en blanco: solo cuentan las del programador y el worker que arrancan
  # ahora (además llevan la versión).
  limpiar_senales
  $COMPOSE up -d --remove-orphans

  echo "==> Comprobando que $VERSION atiende antes de abrir"
  intentos=0
  until disponible; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 30 ]; then
      echo "!! $VERSION no quedó lista en 5 minutos. Sigue en MANTENIMIENTO, sin abrir al público:"
      $COMPOSE exec -T -u www-data api php artisan agendauno:verificar-produccion --disponibilidad || true
      echo "   - corrige lo de arriba y ábrela: $COMPOSE exec -u www-data api php artisan up"
      echo "   - o regresa el código a $ANTERIOR: ./volver.sh $ANTERIOR"
      echo "     (la base no se revierte; si la migración no fuera compatible con $ANTERIOR,"
      echo "     restaura los respaldos recién tomados: docs/DESPLIEGUE.md → Restaurar)."
      anotar "$ANTERIOR -> $VERSION (fallida: no se abrió)"
      exit 1
    fi
    sleep 10
  done

  echo "==> Abriendo $VERSION"
  $COMPOSE exec -T -u www-data api php artisan up
  echo "$VERSION" > .version-actual
  for componente in $COMPONENTES_SOLOS; do
    echo "$VERSION" > "$(archivo_version "$componente")"
  done
  anotar "$ANTERIOR -> $VERSION"

  echo "==> Confirmando que la cola procesa"
  if ! cola_procesa; then
    echo "!! $VERSION está abierta, pero la cola no procesó trabajos en 3 minutos (correos,"
    echo "   avisos y cobros esperan). Revisa: $COMPOSE logs --tail=100 worker scheduler"
    echo "   Si es de esta versión, regresa con: ./volver.sh $ANTERIOR"
    exit 2
  fi

  echo "==> Verificación completa de producción"
  if ! $COMPOSE exec -T -u www-data api php artisan agendauno:verificar-produccion; then
    echo "!! $VERSION está abierta y atiende, pero faltan puntos para operar en producción (arriba)."
    echo "   Corrígelos. Si fueran de esta versión, regresa con: ./volver.sh $ANTERIOR"
    exit 2
  fi
  echo "==> Listo: $VERSION en marcha (la anterior era $ANTERIOR; para regresar: ./volver.sh)"
}

# Publica solo un componente sin estado (la aplicación web o una landing, ADR 0112):
# sin mantenimiento, respaldos ni migraciones, porque no toca datos. Si la versión nueva
# no atiende, vuelve a levantar la anterior.
actualizar_componente() {
  componente="$1"
  variable="$(variable_version "$componente")"
  anterior="$(version_de "$componente")"

  echo "==> Trayendo el código ($2)"
  git fetch --tags origin
  git checkout --quiet "$2"
  nueva="$(git rev-parse --short HEAD)"
  echo "    $componente: versión nueva $nueva (actual: $anterior)"
  export "$variable=$nueva"

  echo "==> Construyendo $componente $nueva"
  $COMPOSE build "$componente"
  echo "==> Levantando $componente $nueva"
  $COMPOSE up -d --no-deps --no-build "$componente"

  echo "==> Comprobando que $componente $nueva atiende"
  if ! componente_atiende "$componente"; then
    echo "!! $componente $nueva no atendió en 2 minutos: se vuelve a levantar $anterior."
    echo "   Revisa: ./compose.sh logs --tail=100 $componente web"
    export "$variable=$anterior"
    $COMPOSE up -d --no-deps --no-build "$componente" || true
    anotar "$componente: $anterior -> $nueva (fallida: no atendió)"
    exit 1
  fi
  echo "$nueva" > "$(archivo_version "$componente")"
  anotar "$componente: $anterior -> $nueva"
  echo "==> Listo: $componente $nueva en marcha (la anterior era $anterior; para regresar: ./volver.sh --solo $componente)"
}

respaldar() {
  # Los respaldos corren como www-data, igual que los nocturnos del programador: su
  # carpeta de trabajo debe ser suya (antes, un respaldo hecho a mano con
  # `exec` sin `-u www-data` la dejaba de root).
  VERSION="$ANTERIOR" $COMPOSE exec -T api sh -c 'd=storage/app/respaldos-temp; mkdir -p "$d" && chown www-data:www-data "$d" && chmod 700 "$d"' \
    || return 1
  if [ "${SIN_RESPALDO_PLATAFORMA:-0}" = "1" ]; then
    # Solo la primera vez: la versión en marcha aún no tiene este comando; respalda
    # MySQL con la herramienta del proveedor antes de usar esta opción.
    echo "    (se omite el respaldo de la plataforma: SIN_RESPALDO_PLATAFORMA=1)"
  else
    VERSION="$ANTERIOR" $COMPOSE exec -T -u www-data api php artisan agendauno:respaldar-plataforma || return 1
  fi
  VERSION="$ANTERIOR" $COMPOSE exec -T -u www-data api php artisan agendauno:respaldar-estudios || return 1
}

limpiar_senales() {
  for llave in operacion:latido:programador operacion:latido:cola operacion:arranque:cola; do
    $COMPOSE run --rm api php artisan cache:forget "$llave" >/dev/null
  done
}

# Ya abierta: el programador encola el latido cada minuto y el worker lo procesa.
cola_procesa() {
  intentos=0
  until $COMPOSE exec -T -u www-data api php artisan agendauno:latido --verificar=cola --minutos=2 >/dev/null 2>&1; do
    intentos=$((intentos + 1))
    [ "$intentos" -ge 18 ] && return 1
    sleep 10
  done
}

reabrir_anterior() {
  echo "   Reabriendo la versión en marcha ($ANTERIOR) sin actualizar."
  VERSION="$ANTERIOR" $COMPOSE start worker scheduler || true
  VERSION="$ANTERIOR" $COMPOSE exec -T -u www-data api php artisan up || true
}

# ¿Atiende la versión nueva? Lo mínimo por dentro (base, caché, esquema al día,
# programador latiendo y worker arrancado, ambos de esta versión) y una petición real
# por nginx y PHP-FPM, con la galleta que deja pasar el mantenimiento.
disponible() {
  $COMPOSE exec -T -u www-data api php artisan agendauno:verificar-produccion --disponibilidad >/dev/null 2>&1 || return 1
  galleta="$(curl -s -o /dev/null -D - -H "Host: $DOMINIO" "http://127.0.0.1:8080/$SECRETO" \
    | tr -d '\r' | sed -n 's/^[Ss]et-[Cc]ookie: *\(laravel_maintenance=[^;]*\).*/\1/p' | head -n 1)"
  [ -n "$galleta" ] || return 1
  curl -fsS -o /dev/null -H "Host: $DOMINIO" -H "Cookie: $galleta" "http://127.0.0.1:8080/api/v1/health?estricto=1" || return 1
  # Y la landing de cada producto, por nginx (su robots no tiene respaldo en la
  # aplicación: si la landing no responde, falla).
  curl -fsS -o /dev/null -H "Host: $DOMINIO" "http://127.0.0.1:8080/robots.txt" || return 1
  curl -fsS -o /dev/null -H "Host: ${DOMINIO_TURNOUNO:-turnouno.mx}" "http://127.0.0.1:8080/robots.txt"
}

principal "$@"
exit
