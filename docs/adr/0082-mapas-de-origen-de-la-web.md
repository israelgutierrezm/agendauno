# ADR 0082 — Mapas de origen para los errores de la web

Estado: Aceptado (2026-09-30). Completa el ADR 0080.

## Contexto

Los errores de la web llegaban con la traza del JavaScript compilado
(«assets/index-abc.js:1:234»). Servía para agrupar, pero había dos problemas:

- no decía el archivo ni la línea reales;
- los nombres compilados cambian en cada publicación, así que el mismo error formaba
  un grupo nuevo en cada versión y no se detectaba cuando un error resuelto volvía.

Publicar los mapas de origen expondría el código de la aplicación a cualquiera.

## Decisión

- **Compilación:** Vite genera los mapas en modo `hidden`, sin el comentario que los
  anuncia al navegador.
- **Imagen web:** los saca de lo que nginx publica y los guarda aparte. Al arrancar,
  `web-mapas.sh` los copia al volumen `mapas-web`.
  - Conserva los de versiones anteriores un mes, por las pestañas que siguen abiertas
    tras publicar.
  - La API monta ese volumen solo de lectura (`MAPAS_WEB_DIR`, config
    `agendauno.errores.mapas_web`).
- **API:** al recibir un error de la web, `MapasDeOrigen` traduce su lugar y cada
  lugar de la traza al archivo y la línea originales, p. ej.
  «src/views/AgendaView.vue:120:5».
  - Lee el formato Source Map v3 (VLQ) sin dependencias nuevas. Da los mismos
    resultados que la librería de referencia de Vite.
  - Solo acepta nombres de `assets/*.js`, así que no se puede pedir otro archivo.
  - Las librerías se muestran desde `node_modules/`.
  - El lugar compilado queda en el contexto (`compilado`).
  - La huella usa el lugar original: el mismo error se agrupa entre publicaciones y
    la regresión del ADR 0080 funciona también en la web.
- Sin mapa (en desarrollo, o una compilación ya vieja) no se traduce y todo sigue como
  antes.

## Consecuencias

- El superadmin ve dónde falló la web en el código fuente, sin publicar ese código.
- Los errores de la web se agrupan igual entre versiones.
- Vue mapea el texto de sus plantillas de forma gruesa: un error que nace ahí puede
  apuntar al componente vecino. Los de código (llamadas, propiedades) apuntan bien.
- El volumen `mapas-web` se regenera en cada publicación; no se respalda.
