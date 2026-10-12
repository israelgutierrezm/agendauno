# ADR 0140 — Complementos que se piden: Familias como plus

Estado: Aceptado (2026-10-12). Pedido del dueño:

> «Familia nunca está activo y no aparece. Actualmente no le encuentro mucha utilidad a
> menos de que un negocio lo requiera; quiero que parezca que no existe, aunque sí
> quiero que lo ofrezcas como un plus existente, para promoverlo.»

## Contexto

Familias (ADR 0123) es un módulo que el superadmin enciende por negocio. Apagado, sus
rutas responden 403 y la web y la app no lo muestran. Pero el negocio no tenía cómo
saber que existía ni cómo pedirlo. Los demos lo encendían (bloque 32) y así parecía
parte de todo negocio.

## Decisión

- **Sigue apagado por omisión.** En el panel, el portal y la app no existe mientras no
  se encienda. Los demos ya no lo encienden.
- **Se ofrece como complemento.** En «Mi suscripción», la sección «Complementos» lista
  los módulos que existen (hoy, Familias), con lo que hacen y su estado:
  - activo;
  - solicitado, con la fecha;
  - o el botón «Solicitarlo».
- **Pedirlo** (`POST /renta/complementos/{modulo}/solicitud`, quien administra el
  negocio) registra una alerta de la plataforma (`modulo_solicitado`). Le llega al
  superadmin con el nombre del negocio y de quien lo pidió. Pedirlo de nuevo no duplica
  la alerta: suma una vez más y vuelve a avisar pasadas las horas de espera.
- **Activarlo** sigue siendo del superadmin, en la ficha del negocio. Al encenderlo,
  aparece activo y deja de ofrecerse.
- La descripción usa las palabras del negocio (alumno o cliente).

## Consecuencias

- Familias no estorba a quien no lo necesita y se puede promover.
- El mismo mecanismo sirve para cualquier módulo nuevo: basta con agregarlo a
  `ModuloNegocio`.
