#!/usr/bin/env sh
# docker compose con la versión en marcha de cada componente (ADR 0112) y los archivos de
# compose de web.env (Traefik, Cloudflare). Úsalo para todo `up`, `run` o `build` a mano:
# sin las versiones, Compose usaría las imágenes `latest` (las de la primera instalación).
#
#   ./compose.sh up -d            # p. ej. tras cambiar api.env
#   ./compose.sh ps
set -eu
cd "$(dirname "$0")"
. ./versiones.sh
cargar_versiones
exec docker compose --env-file web.env "$@"
