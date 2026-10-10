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
ARG VITE_APP_VERSION="dev"
ARG GOOGLE_SITE_VERIFICATION=""
# Sin mapas de origen: no se publican (ADR 0082).
RUN case "$PRODUCTO" in agendauno|turnouno) ;; *) echo "PRODUCTO desconocido: $PRODUCTO" >&2; exit 1 ;; esac \
 && npm run "build:$PRODUCTO" \
 && find "dist/$PRODUCTO" -name '*.map' -delete \
 && mkdir /landing \
 && cp -r "dist/$PRODUCTO/index.html" "dist/$PRODUCTO/sitemap.xml" "dist/$PRODUCTO/robots.txt" \
      "dist/$PRODUCTO/assets-$PRODUCTO" /landing/ \
 && for pagina in "dist/$PRODUCTO"/software-para-*; do cp -r "$pagina" /landing/; done

FROM nginx:1.27-alpine
COPY --from=build /landing /usr/share/nginx/html
COPY infra/produccion/nginx-landing.conf /etc/nginx/conf.d/default.conf
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD wget -q -O /dev/null http://127.0.0.1/robots.txt || exit 1
