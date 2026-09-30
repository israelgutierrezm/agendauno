#!/bin/sh
# Mapas de origen de esta compilación para la API (ADR 0082): no se publican; la API
# los lee de un volumen compartido para traducir los errores de la web al archivo y la
# línea originales. Se conservan los de versiones anteriores un mes (pestañas viejas
# que siguen abiertas tras publicar).
set -e
if [ -d /mapas-compartidos ] && [ -d /usr/share/nginx/mapas ]; then
  cp -r /usr/share/nginx/mapas/. /mapas-compartidos/
  find /mapas-compartidos -name '*.map' -mtime +30 -delete || true
fi
