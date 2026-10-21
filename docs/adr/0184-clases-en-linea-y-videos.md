# ADR 0184 — Clases en línea y biblioteca de videos

Estado: Aceptado (2026-10-20). Resuelve la decisión D4 del comparativo: es una función
incluida, no un complemento.

## Contexto

Atom (en su plan Corporativo) y Buq (con un módulo propio) venden clases en línea y una
biblioteca de videos. Los estudios usan Zoom, Meet, YouTube o Vimeo. No necesitan que
nosotros alojemos el video.

## Decisión

- **Clase en línea.** Cada clase (oferta) puede tener un enlace https
  (`ofertas.enlace_en_linea`, migración de negocio 000177). Solo lo ve quien tiene la
  reserva confirmada, en su cuenta (`GET /mi/perfil` → `reservas[].enlace_en_linea`) y en
  la app («Entrar a la clase en línea»). No aparece en la página pública ni en los
  eventos.
- **Biblioteca de videos** (`videos`, `video_producto`). Cada video es un enlace https
  con título, descripción, categoría, duración, si está visible y su orden. El negocio
  elige qué planes lo incluyen; sin ninguno, lo ve cualquier cliente con un plan vigente.
  - El cliente (`GET /mi/videos`) recibe el enlace solo de los videos que su plan
    incluye. De los demás ve el título y qué plan los incluye, para invitarlo a ese plan.
  - YouTube y Vimeo se reproducen dentro de la página con su reproductor
    (`youtube-nocookie`, `player.vimeo.com`), y solo si el identificador es válido.
    Cualquier otro sitio se abre aparte.
  - No se sube video ni se pega código.
- Lo editan quienes editan el catálogo (`catalogo.gestionar`). Es solo para negocios de
  clases.

## Consecuencias

- El negocio paga su Zoom o su YouTube; nosotros no cargamos con el almacenamiento.
- Un enlace compartido fuera de la plataforma se puede volver a compartir. Proteger el
  contenido corre por cuenta de la plataforma de video (por ejemplo, un video no listado
  o con dominio restringido en Vimeo).
