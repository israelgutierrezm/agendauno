# syntax=docker/dockerfile:1
#
# Web de AgendaUno para producción: compila el sitio comercial (HTML completo) y la
# aplicación, y los sirve con nginx, que además pasa /api a PHP-FPM y sirve /storage.
# Contexto de build: la raíz del repositorio.

FROM node:22-alpine AS build
WORKDIR /web
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY apps/web/ ./

# Variables públicas de compilación (quedan dentro del JavaScript: nada secreto).
# VITE_API_URL vacío = la API en el mismo dominio (nginx pasa /api a PHP).
ARG VITE_API_URL=""
ARG VITE_DOMINIO_PUBLICO="agendauno.mx"
ARG VITE_GOOGLE_CLIENT_ID=""
ARG VITE_RECAPTCHA_SITE_KEY=""
ARG VITE_ANALYTICS_ENDPOINT=""
ARG VITE_APP_VERSION="dev"
ARG GOOGLE_SITE_VERIFICATION=""
# Los mapas de origen salen de lo que se publica (ADR 0082).
RUN npm run build \
 && mkdir -p /web/mapas \
 && cd dist \
 && find . -name '*.map' -exec sh -c 'mkdir -p "/web/mapas/$(dirname "$1")" && mv "$1" "/web/mapas/$1"' _ {} \;

FROM nginx:1.27-alpine
COPY --from=build /web/dist /usr/share/nginx/html
# Fuera de la raíz de nginx; al arrancar se copian al volumen que lee la API.
COPY --from=build /web/mapas /usr/share/nginx/mapas
COPY --chmod=0755 infra/produccion/web-mapas.sh /docker-entrypoint.d/40-agendauno-mapas.sh
# El dominio se pone al arrancar (variable DOMINIO), con las plantillas de nginx.
COPY infra/produccion/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY infra/produccion/nginx-comun.conf /etc/nginx/snippets/agendauno-comun.conf
COPY infra/produccion/nginx-cabeceras.conf /etc/nginx/snippets/agendauno-cabeceras.conf
