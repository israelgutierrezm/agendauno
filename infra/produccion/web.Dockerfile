# syntax=docker/dockerfile:1
#
# Web de la plataforma para producción: compila la aplicación (dist/app: panel,
# portal, escaparate de cada negocio, registro, acceso, superadmin) y la sirve con
# nginx, que es la entrada de los dos dominios: pasa /api a PHP-FPM, sirve /storage y
# pasa la portada y las páginas por giro a la landing de cada producto, que es otra
# imagen (landing.Dockerfile, ADR 0112). Contexto de build: la raíz del repositorio.

FROM node:22-alpine AS build
WORKDIR /web
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY apps/web/ ./

# Variables públicas de compilación (quedan dentro del JavaScript: nada secreto).
# VITE_API_URL vacío = la API en el mismo dominio (nginx pasa /api a PHP).
ARG VITE_API_URL=""
ARG VITE_DOMINIO_PUBLICO="agendauno.mx"
ARG VITE_DOMINIO_TURNOUNO="turnouno.mx"
# Nombre de TurnoUno mientras no tenga identidad definitiva (opcional).
ARG VITE_TURNOUNO_NOMBRE=""
ARG VITE_GOOGLE_CLIENT_ID=""
ARG VITE_RECAPTCHA_SITE_KEY=""
ARG VITE_ANALYTICS_ENDPOINT=""
ARG VITE_VENTAS_WHATSAPP=""
ARG VITE_APP_VERSION="dev"
ARG GOOGLE_SITE_VERIFICATION=""
# Los mapas de origen salen de lo que se publica (ADR 0082); quedan en mapas/app/…, como
# antes. La revisión de tipos y las pruebas corren en el CI.
RUN npm run build:app \
 && mkdir -p /web/mapas/app \
 && cd dist/app \
 && find . -name '*.map' -exec sh -c 'mkdir -p "/web/mapas/app/$(dirname "$1")" && mv "$1" "/web/mapas/app/$1"' _ {} \;

FROM nginx:1.27-alpine
COPY --from=build /web/dist/app /usr/share/nginx/html/app
# Fuera de la raíz de nginx; al arrancar se copian al volumen que lee la API.
COPY --from=build /web/mapas /usr/share/nginx/mapas
COPY --chmod=0755 infra/produccion/web-mapas.sh /docker-entrypoint.d/40-agendauno-mapas.sh
# Los dominios se ponen al arrancar (DOMINIO y DOMINIO_TURNOUNO), con las plantillas de
# nginx.
COPY infra/produccion/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY infra/produccion/nginx-comun.conf /etc/nginx/snippets/agendauno-comun.conf
COPY infra/produccion/nginx-cabeceras.conf /etc/nginx/snippets/agendauno-cabeceras.conf
COPY infra/produccion/nginx-landing-proxy.conf /etc/nginx/snippets/agendauno-landing-proxy.conf
