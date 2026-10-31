# syntax=docker/dockerfile:1
#
# Landing de un producto (ADR 0112): solo su portada, sus páginas por giro, su sitemap,
# su robots y sus recursos (/assets-{producto}/), con HTML completo pre-generado. Se
# publica sin la aplicación ni la API: `./actualizar.sh --solo landing-agendauno`. El
# nginx de `web` le pasa esas rutas; lo demás de su dominio lo sirve la aplicación.
# Contexto de build: la raíz del repositorio.
#
#   docker build -f infra/produccion/landing.Dockerfile --build-arg PRODUCTO=turnouno .

FROM node:22-alpine AS build
WORKDIR /web
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY apps/web/ ./

# agendauno | turnouno
ARG PRODUCTO=agendauno
# Variables públicas de compilación (quedan en el HTML y el JavaScript: nada secreto).
ARG VITE_API_URL=""
ARG VITE_DOMINIO_PUBLICO="agendauno.mx"
ARG VITE_DOMINIO_TURNOUNO="turnouno.mx"
ARG VITE_TURNOUNO_NOMBRE=""
ARG VITE_GOOGLE_CLIENT_ID=""
ARG VITE_RECAPTCHA_SITE_KEY=""
ARG VITE_ANALYTICS_ENDPOINT=""
ARG VITE_VENTAS_WHATSAPP=""
# Canal de soporte de las landings (por omisión, soporte@{dominio} y el WhatsApp provisional).
ARG VITE_SOPORTE_CORREO=""
ARG VITE_TURNOUNO_SOPORTE_CORREO=""
ARG VITE_SOPORTE_WHATSAPP=""
# Enlaces de la app en cada tienda y lo que se puede anunciar (ADR 0234).
ARG VITE_AGENDAUNO_PLAY_URL=""
ARG VITE_AGENDAUNO_APP_STORE_URL=""
ARG VITE_TURNOUNO_PLAY_URL=""
ARG VITE_TURNOUNO_APP_STORE_URL=""
ARG VITE_INTEGRACION_WELLHUB=""
ARG VITE_INTEGRACION_TOTALPASS=""
ARG VITE_INTEGRACION_FITPASS=""
ARG VITE_INTEGRACION_BANDEJA_WHATSAPP=""
ARG VITE_INTEGRACION_BANDEJA_INSTAGRAM=""
ARG VITE_INTEGRACION_BANDEJA_MESSENGER=""
ARG VITE_INTEGRACION_APPLE_WALLET=""
ARG VITE_INTEGRACION_GOOGLE_WALLET=""
ARG VITE_INTEGRACION_GOOGLE_CALENDAR=""
ARG VITE_INTEGRACION_OUTLOOK=""
ARG VITE_APP_VERSION="dev"
ARG GOOGLE_SITE_VERIFICATION=""
# La API de donde se leen los precios publicados al pre-generar (p. ej. https://agendauno.mx):
# el HTML para buscadores dice lo mismo que la tabla y la contratación. Vacía: el respaldo.
ARG PRECIOS_API_URL=""
# Sin mapas de origen: no se publican (ADR 0082).
RUN case "$PRODUCTO" in agendauno|turnouno) ;; *) echo "PRODUCTO desconocido: $PRODUCTO" >&2; exit 1 ;; esac \
 && npm run "build:$PRODUCTO" \
 && find "dist/$PRODUCTO" -name '*.map' -delete \
 && mkdir /landing \
 && cp -r "dist/$PRODUCTO/index.html" "dist/$PRODUCTO/sitemap.xml" "dist/$PRODUCTO/robots.txt" \
      "dist/$PRODUCTO/assets-$PRODUCTO" "dist/$PRODUCTO/preguntas-frecuentes" /landing/ \
 && for pagina in "dist/$PRODUCTO"/software-para-*; do cp -r "$pagina" /landing/; done

FROM nginx:1.27-alpine
COPY --from=build /landing /usr/share/nginx/html
COPY infra/produccion/nginx-landing.conf /etc/nginx/conf.d/default.conf
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1/robots.txt || exit 1
