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
RUN npm run build

FROM nginx:1.27-alpine
COPY --from=build /web/dist /usr/share/nginx/html
# El dominio se pone al arrancar (variable DOMINIO), con las plantillas de nginx.
COPY infra/produccion/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY infra/produccion/nginx-comun.conf /etc/nginx/snippets/agendauno-comun.conf
