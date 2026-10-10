# shellcheck shell=sh
# Lo común de actualizar.sh, volver.sh y compose.sh (ADR 0112): la versión en marcha de
# cada componente. La plataforma (api, worker, scheduler) va en .version-actual; la
# aplicación web y cada landing, en .version-<servicio>, porque también se publican
# solas (./actualizar.sh --solo landing-turnouno). Sin su archivo, un componente va con
# la plataforma.

# Componentes que se publican solos.
COMPONENTES_SOLOS="web landing-agendauno landing-turnouno"

es_componente_solo() {
  case " $COMPONENTES_SOLOS " in *" $1 "*) return 0 ;; *) return 1 ;; esac
}

archivo_version() {
  if [ "$1" = plataforma ]; then echo .version-actual; else echo ".version-$1"; fi
}

# Variable de docker-compose.yml con la etiqueta de la imagen de un componente.
variable_version() {
  case "$1" in
    web) echo VERSION_WEB ;;
    landing-agendauno) echo VERSION_LANDING_AGENDAUNO ;;
    landing-turnouno) echo VERSION_LANDING_TURNOUNO ;;
    *) echo VERSION ;;
  esac
}

version_de() {
  cat "$(archivo_version "$1")" 2>/dev/null || cat .version-actual 2>/dev/null || echo latest
}

# Exporta la versión en marcha de cada componente (sin pisar las que ya se fijaron) y
# los archivos de compose de web.env (Traefik, Cloudflare).
cargar_versiones() {
  : "${VERSION:=$(version_de plataforma)}"
  : "${VERSION_WEB:=$(version_de web)}"
  : "${VERSION_LANDING_AGENDAUNO:=$(version_de landing-agendauno)}"
  : "${VERSION_LANDING_TURNOUNO:=$(version_de landing-turnouno)}"
  export VERSION VERSION_WEB VERSION_LANDING_AGENDAUNO VERSION_LANDING_TURNOUNO
  archivos="$(sed -n 's/^COMPOSE_FILE=//p' web.env 2>/dev/null | tr -d '\r' | head -n 1)"
  if [ -n "$archivos" ]; then
    COMPOSE_FILE="$archivos"
    export COMPOSE_FILE
  fi
}

anotar() {
  echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $1" >> .historial-versiones
}

# ¿Atiende un componente que se publica solo? Una petición real por nginx (el de `web`,
# en 127.0.0.1:8080) a algo que solo él sirve: el robots de cada landing (sin respaldo
# en la aplicación) o una página de la aplicación. Hasta 2 minutos.
componente_atiende() {
  dominio="$(sed -n 's/^DOMINIO=//p' web.env | tr -d '\r' | head -n 1)"
  dominio_turnouno="$(sed -n 's/^DOMINIO_TURNOUNO=//p' web.env | tr -d '\r' | head -n 1)"
  case "$1" in
    web) host="$dominio" ruta=/entrar ;;
    landing-agendauno) host="$dominio" ruta=/robots.txt ;;
    landing-turnouno) host="${dominio_turnouno:-turnouno.mx}" ruta=/robots.txt ;;
    *) return 1 ;;
  esac
  intentos=0
  until curl -fsS -o /dev/null -H "Host: $host" "http://127.0.0.1:8080$ruta"; do
    intentos=$((intentos + 1))
    [ "$intentos" -ge 12 ] && return 1
    sleep 10
  done
}

# Las imágenes de los servicios dados (todos los que se construyen, si no se dan), con la
# versión exportada de cada uno: del registro que publica el CI (REGISTRO en web.env,
# p. ej. ghcr.io/dueno; ADR 0113), etiquetadas como las nombra docker-compose.yml, o
# construidas aquí si no hay registro.
obtener_imagenes() {
  registro="$(sed -n 's/^REGISTRO=//p' web.env 2>/dev/null | tr -d '\r' | head -n 1)"
  if [ -z "$registro" ]; then
    $COMPOSE build "$@"
    return
  fi
  for servicio in ${*:-api web landing-agendauno landing-turnouno}; do
    case "$servicio" in worker | scheduler) servicio=api ;; esac
    variable="$(variable_version "$servicio")"
    eval "etiqueta=\${$variable}"
    echo "    $registro/agendauno-$servicio:$etiqueta"
    docker pull --quiet "$registro/agendauno-$servicio:$etiqueta" >/dev/null || return 1
    docker tag "$registro/agendauno-$servicio:$etiqueta" "agendauno-$servicio:$etiqueta" || return 1
  done
}
