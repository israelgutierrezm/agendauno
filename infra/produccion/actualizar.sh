#!/usr/bin/env sh
# Actualiza AgendaUno en el servidor a una versión nueva (por defecto, lo último de
# main) sin perder el camino de regreso. Guía: docs/DESPLIEGUE.md.
#
#   ./actualizar.sh            # lo último de origin/main
#   ./actualizar.sh v1.4.0     # una etiqueta o commit
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
# 4. Solo la abre si atiende: base, caché, cola y programador de la versión nueva,
#    esquema al día (verificar-produccion --disponibilidad) y una petición real por
#    nginx. Si no, se queda en mantenimiento, no se anota como actual y sale con error.
# 5. Ya abierta, corre la verificación completa: si falta algo para operar en
#    producción, lo dice y sale con error (no «Listo»).
#
# Nunca revierte la base sola: las migraciones se escriben para que la versión
# anterior siga funcionando con el esquema nuevo; si una no lo fuera, se restaura a
# mano con los respaldos recién tomados (docs/DESPLIEGUE.md → Restaurar).
set -eu

# Todo va dentro de una función: `git checkout` puede cambiar este mismo archivo
# mientras corre, y sh lo lee conforme avanza. Así se lee completo antes de empezar.
principal() {
  cd "$(dirname "$0")"
  COMPOSE="docker compose --env-file web.env"
  REF="${1:-origin/main}"
  ANTERIOR="$(cat .version-actual 2>/dev/null || echo latest)"
  DOMINIO="$(sed -n 's/^DOMINIO=//p' web.env | tr -d '\r' | head -n 1)"
  # Ruta secreta para probar la versión nueva por HTTP mientras sigue en
  # mantenimiento (empieza con api/ para que nginx la pase a PHP).
  SECRETO="api/revision-$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"

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
    echo "==> Punto de corte: mantenimiento y sin tareas en curso"
    VERSION="$ANTERIOR" $COMPOSE exec -T api php artisan down --retry=60 --secret="$SECRETO"
    echo "    esperando a que la cola y el programador terminen lo que están haciendo…"
    VERSION="$ANTERIOR" $COMPOSE stop worker scheduler
    # Si alguna tarea se cortó al vencer la espera, su candado no debe dejarla
    # bloqueada 24 h.
    VERSION="$ANTERIOR" $COMPOSE exec -T api php artisan schedule:clear-cache >/dev/null

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
    || ! $COMPOSE run --rm api php artisan turnouno:migrar-estudios --force; then
    echo "!! Falló una migración. Todo sigue en MANTENIMIENTO, con la versión $ANTERIOR detenida a medias"
    echo "   (sin cola ni programador). Parte del esquema pudo haber cambiado:"
    echo "   - revisa el error y, si $ANTERIOR funciona con el esquema actual, reábrela:"
    echo "       VERSION=$ANTERIOR $COMPOSE start worker scheduler"
    echo "       VERSION=$ANTERIOR $COMPOSE exec api php artisan up"
    echo "   - si no, restaura los respaldos recién tomados (docs/DESPLIEGUE.md → Restaurar)."
    anotar "$ANTERIOR -> $VERSION (fallida: migración)"
    exit 1
  fi

  echo "==> Levantando $VERSION (sigue en mantenimiento)"
  # Latidos en blanco: solo cuentan los de la cola y el programador nuevos.
  $COMPOSE run --rm api php artisan cache:forget operacion:latido:programador >/dev/null
  $COMPOSE run --rm api php artisan cache:forget operacion:latido:cola >/dev/null
  $COMPOSE up -d --remove-orphans

  echo "==> Comprobando que $VERSION atiende antes de abrir"
  intentos=0
  until disponible; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 30 ]; then
      echo "!! $VERSION no quedó lista en 5 minutos. Sigue en MANTENIMIENTO, sin abrir al público:"
      $COMPOSE exec -T api php artisan turnouno:verificar-produccion --disponibilidad || true
      echo "   - corrige lo de arriba y ábrela: $COMPOSE exec api php artisan up"
      echo "   - o regresa el código a $ANTERIOR: ./volver.sh $ANTERIOR"
      echo "     (la base no se revierte; si la migración no fuera compatible con $ANTERIOR,"
      echo "     restaura los respaldos recién tomados: docs/DESPLIEGUE.md → Restaurar)."
      anotar "$ANTERIOR -> $VERSION (fallida: no se abrió)"
      exit 1
    fi
    sleep 10
  done

  echo "==> Abriendo $VERSION"
  $COMPOSE exec -T api php artisan up
  echo "$VERSION" > .version-actual
  anotar "$ANTERIOR -> $VERSION"

  echo "==> Verificación completa de producción"
  if ! $COMPOSE exec -T api php artisan turnouno:verificar-produccion; then
    echo "!! $VERSION está abierta y atiende, pero faltan puntos para operar en producción (arriba)."
    echo "   Corrígelos. Si fueran de esta versión, regresa con: ./volver.sh $ANTERIOR"
    exit 2
  fi
  echo "==> Listo: $VERSION en marcha (la anterior era $ANTERIOR; para regresar: ./volver.sh)"
}

anotar() {
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $1" >> .historial-versiones
}

respaldar() {
  if [ "${SIN_RESPALDO_PLATAFORMA:-0}" = "1" ]; then
    # Solo la primera vez: la versión en marcha aún no tiene este comando; respalda
    # MySQL con la herramienta del proveedor antes de usar esta opción.
    echo "    (se omite el respaldo de la plataforma: SIN_RESPALDO_PLATAFORMA=1)"
  else
    VERSION="$ANTERIOR" $COMPOSE exec -T api php artisan turnouno:respaldar-plataforma || return 1
  fi
  VERSION="$ANTERIOR" $COMPOSE exec -T api php artisan turnouno:respaldar-estudios || return 1
}

reabrir_anterior() {
  echo "   Reabriendo la versión en marcha ($ANTERIOR) sin actualizar."
  VERSION="$ANTERIOR" $COMPOSE start worker scheduler || true
  VERSION="$ANTERIOR" $COMPOSE exec -T api php artisan up || true
}

# ¿Atiende la versión nueva? Lo mínimo por dentro (base, caché, cola, programador,
# esquema al día) y una petición real por nginx y PHP-FPM, con la galleta que deja
# pasar el mantenimiento.
disponible() {
  $COMPOSE exec -T api php artisan turnouno:verificar-produccion --disponibilidad >/dev/null 2>&1 || return 1
  galleta="$(curl -s -o /dev/null -D - -H "Host: $DOMINIO" "http://127.0.0.1:8080/$SECRETO" \
    | tr -d '\r' | sed -n 's/^[Ss]et-[Cc]ookie: *\(laravel_maintenance=[^;]*\).*/\1/p' | head -n 1)"
  [ -n "$galleta" ] || return 1
  curl -fsS -o /dev/null -H "Host: $DOMINIO" -H "Cookie: $galleta" "http://127.0.0.1:8080/api/v1/health?estricto=1"
}

principal "$@"
exit
