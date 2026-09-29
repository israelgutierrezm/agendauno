# ADR 0066 — Foto por servicio

Estado: Aceptado (2026-09-29).

## Contexto

Al agendar, los servicios eran solo texto: nombre, duración, descripción y precio. En
AgendaPro y en las páginas de barberías, spas y estudios, cada servicio lleva una
foto que ayuda a elegir.

## Decisión

- `ofertas.foto_ruta` (base del negocio): una foto por servicio o clase.
  - Se sube con `POST /ofertas/{id}/foto` y se quita con `DELETE`, con permiso
    `catalogo.gestionar`.
  - Solo PNG, JPG o WebP de hasta 4 MB; SVG no, por riesgo de XSS.
  - Carpeta por negocio y nombre al azar; la nueva reemplaza a la anterior.
- `foto_url` en el catálogo, en las opciones para agendar y en el escaparate.
- **Web**:
  - Catálogo: la foto se sube al configurar el servicio, con el mismo
    `CargadorImagen` de la portada y las sedes;
  - la lista del catálogo muestra la miniatura;
  - agendar y la página del negocio muestran la foto junto a cada servicio.
- La app sigue mostrando los servicios como lista, sin foto.

## Consecuencias

- El cliente reconoce el servicio por su foto al elegir.
- Sin foto, todo se ve como antes.
