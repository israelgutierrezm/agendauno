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
# 4. Antes de abrir, lo crítico (ADR 0135): que atienda (base, caché, esquema al día,
#    el programador nuevo latiendo, el worker nuevo arrancado y una petición real por
#    nginx) y que su cola procese un trabajo de prueba (un worker de un solo uso, porque
#    en mantenimiento el de siempre no toma trabajos). También corre la verificación
#    completa, que informa.
# 5. Ya abierta, confirma que el worker de siempre procesa (el latido pasa por él).
#
# Si algo crítico falla después del cambio de versión (no atiende, su cola no procesa,
# antes o después de abrir), avisa al equipo de la plataforma y VUELVE SOLA a la
# versión anterior (./volver.sh): una versión que falla no se queda abierta ni en
# mantenimiento. Lo que falta de la verificación completa (de la instalación, no de la
# versión) solo se avisa y sale con error, sin «Listo».
#
# Nunca revierte la base sola: las migraciones se escriben para que la versión
# anterior siga funcionando con el esquema nuevo; si una no lo fuera, se restaura a
# mano con los respaldos recién tomados (docs/DESPLIEGUE.md → Restaurar).
set -eu

# Todo va dentro de una función: `git checkout` puede cambiar este mismo archivo
# mientras corre, y sh lo lee conforme avanza. Así se lee completo antes de empezar.
principal() {
  cd "$(dirname "$0")"
  # Un solo despliegue a la vez: dos a la vez se pisarían las migraciones (ADR 0232).
  if command -v flock >/dev/null 2>&1; then
    exec 9>.despliegue.lock
    if ! flock -n 9; then
      echo "!! Ya hay otro despliegue en curso en este servidor."
      exit 1
    fi
  fi
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

  echo "==> Imágenes $VERSION (del registro del CI si web.env tiene REGISTRO; si no, se construyen)"
  if ! obtener_imagenes; then
    echo "!! No están las imágenes $VERSION en el registro (¿ya terminó el flujo «Imágenes» de ese commit?)."
    exit 1
  fi

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
    anotar "$ANTERIOR -> $VERSION (fallida: migración)"
    if [ -z "$EN_MARCHA" ]; then
      echo "!! Falló una migración. Primera instalación: no hay versión anterior; sigue en MANTENIMIENTO."
      avisar "Despliegue de $VERSION: falló una migración. Primera instalación: quedó en mantenimiento."
      exit 1
    fi
    # Un error en un solo negocio no deja fuera a todos (ADR 0232): la anterior funciona
    # con el esquema nuevo (las migraciones se escriben para eso). Si una no lo fuera, se
    # restauran los respaldos recién tomados (docs/DESPLIEGUE.md → Restaurar).
    echo "!! Falló una migración: se reabre sola la versión $ANTERIOR."
    reabrir_anterior
    avisar "Despliegue de $VERSION: falló una migración. Se reabrió $ANTERIOR: revisa qué negocio falló con agendauno:migrar-estudios y corrígelo antes de volver a publicar."
    echo "   Parte del esquema pudo haber cambiado. Revisa: $COMPOSE run --rm api php artisan agendauno:migrar-estudios"
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
      echo "!! $VERSION no quedó lista en 5 minutos:"
      $COMPOSE exec -T -u www-data api php artisan agendauno:verificar-produccion --disponibilidad || true
      volver_a_la_anterior "no quedó lista para atender en 5 minutos"
    fi
    sleep 10
  done

  echo "==> Probando la cola de $VERSION antes de abrir"
  if ! $COMPOSE exec -T -u www-data api php artisan agendauno:probar-cola; then
    volver_a_la_anterior "su cola no procesó un trabajo de prueba"
  fi

  echo "==> Verificación completa (antes de abrir; informa)"
  COMPLETA=0
  $COMPOSE exec -T -u www-data api php artisan agendauno:verificar-produccion || COMPLETA=1

  echo "==> Abriendo $VERSION"
  $COMPOSE exec -T -u www-data api php artisan up
  echo "$VERSION" > .version-actual
  for componente in $COMPONENTES_SOLOS; do
    echo "$VERSION" > "$(archivo_version "$componente")"
  done
  anotar "$ANTERIOR -> $VERSION"

  echo "==> Confirmando que el worker de siempre procesa"
  if ! cola_procesa; then
    echo "!! $VERSION está abierta, pero su cola no procesó trabajos en 3 minutos (correos,"
    echo "   avisos y cobros esperan)."
    $COMPOSE logs --tail=50 worker scheduler || true
    volver_a_la_anterior "ya abierta, su cola no procesó trabajos en 3 minutos"
  fi

  if [ "$COMPLETA" -ne 0 ]; then
    echo "!! $VERSION está abierta y atiende, pero faltan puntos para operar en producción (arriba)."
    echo "   Son de la instalación (no de la versión): corrígelos. Si fueran de esta versión,"
    echo "   regresa con: ./volver.sh $ANTERIOR"
    avisar "Despliegue de $VERSION abierto, pero faltan puntos de la verificación de producción: revisa agendauno:verificar-produccion."
    exit 2
  fi
  limpiar_imagenes
  echo "==> Listo: $VERSION en marcha (la anterior era $ANTERIOR; para regresar: ./volver.sh)"
}

# Algo crítico falló después del cambio de versión (ADR 0135): se avisa al equipo de la
# plataforma y se vuelve sola a la anterior, que ya funcionaba (la base no se revierte:
# las migraciones son compatibles con ella). En una primera instalación no hay a cuál
# volver: se queda en mantenimiento.
volver_a_la_anterior() {
  motivo="$1"
  if [ -z "$EN_MARCHA" ] || [ "$ANTERIOR" = latest ]; then
    echo "!! $VERSION $motivo. Primera instalación: no hay versión anterior; sigue en MANTENIMIENTO."
    echo "   Corrige lo de arriba y ábrela: $COMPOSE exec -u www-data api php artisan up"
    avisar "Despliegue de $VERSION: $motivo. Primera instalación: quedó en mantenimiento."
    anotar "$ANTERIOR -> $VERSION (fallida: $motivo)"
    exit 1
  fi

  echo "!! $VERSION $motivo: se vuelve sola a $ANTERIOR."
  avisar "Despliegue de $VERSION: $motivo. Se vuelve sola a $ANTERIOR."
  anotar "$ANTERIOR -> $VERSION (fallida: $motivo; se vuelve a $ANTERIOR)"
  # Con las versiones de lo que está en marcha (no las de la versión fallida).
  if ! (unset VERSION VERSION_WEB VERSION_LANDING_AGENDAUNO VERSION_LANDING_TURNOUNO; ./volver.sh "$ANTERIOR"); then
    echo "!! No se pudo volver a $ANTERIOR (arriba): revisa el servidor ya (docs/DESPLIEGUE.md → Volver)."
    avisar "No se pudo volver a $ANTERIOR tras el despliegue fallido de $VERSION: revisa el servidor ya."
    exit 1
  fi
  echo "==> $VERSION no se quedó: $ANTERIOR atiende de nuevo. Motivo: $motivo."
  echo "   Revisa: $COMPOSE logs --tail=200 api worker scheduler"
  exit 1
}

# Avisa al equipo de la plataforma (el correo de alertas). Si la versión en marcha no
# tiene el comando o no responde, el aviso queda solo en la consola (y en el flujo
# «Desplegar», que falla).
avisar() {
  echo "   Aviso: $1"
  $COMPOSE exec -T -u www-data api php artisan agendauno:avisar-despliegue "$1" >/dev/null 2>&1 || true
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

  echo "==> Imagen de $componente $nueva"
  if ! obtener_imagenes "$componente"; then
    echo "!! No está la imagen de $componente $nueva en el registro."
    exit 1
  fi
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
    # Sin los archivos subidos (no los toca una migración; van en el respaldo diario): con
    # ellos, cada despliegue tardaría más cuanto más suben los negocios (ADR 0232).
    VERSION="$ANTERIOR" $COMPOSE exec -T -u www-data api php artisan agendauno:respaldar-plataforma --sin-archivos || return 1
  fi
  if trae_migraciones_de_negocio; then
    VERSION="$ANTERIOR" $COMPOSE exec -T -u www-data api php artisan agendauno:respaldar-estudios || return 1
  else
    echo "    (esta versión no migra las bases de los negocios: se conserva su respaldo diario)"
  fi
}

# ¿Hay migraciones de negocio entre la versión en marcha y la nueva? Si no se puede saber
# (la anterior no está en el repositorio, o es «latest»), se responde que sí: se respalda.
trae_migraciones_de_negocio() {
  git diff --quiet "$ANTERIOR" "$VERSION" -- ../../apps/api/database/migrations/tenant 2>/dev/null && return 1
  return 0
}

# Cada despliegue suma cuatro imágenes: se conservan las 5 más recientes de cada una, para
# poder volver (ADR 0232). Limpiar nunca hace fallar un despliegue que ya quedó bien.
limpiar_imagenes() {
  for imagen in agendauno-api agendauno-web agendauno-landing-agendauno agendauno-landing-turnouno; do
    docker images --format '{{.Repository}}:{{.Tag}}' \
      | grep -E "(^|/)$imagen:" | grep -v ':<none>' | awk -F: '!visto[$NF]++ { print $NF }' | tail -n +6 \
      | while read -r etiqueta; do
        docker images --format '{{.Repository}}:{{.Tag}}' | grep -E "(^|/)$imagen:$etiqueta\$" \
          | xargs -r docker rmi >/dev/null 2>&1 || true
      done
  done
  docker image prune -f >/dev/null 2>&1 || true
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
