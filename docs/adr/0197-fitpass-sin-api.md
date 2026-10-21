# ADR 0197 — Fitpass, sin API

Estado: Aceptado (2026-10-20).

## Contexto

El comparativo encontró Fitpass en Fitune y en Buq. Wellhub y TotalPass ya validan
visitas por su API (ADR 0148/0149) y se concilian por archivo (ADR 0185). Fitpass no
publica una API para socios: su integración es privada y pide un acuerdo con Fitpass.

## Decisión

- **Fitpass es una plataforma más** (`ProveedorPartner::Fitpass`), en negocios de clases
  en México. Se activa en Integraciones, sin llaves.
- **Recepción valida la visita en la app de socios de Fitpass** y registra aquí su folio
  (`POST /checkins` con `proveedor=fitpass`).
  - El folio se acepta tal cual (letras, números, guiones). La responsabilidad es de
    quien lo registra, y queda quién fue.
  - La visita ocupa su lugar en la clase y quien vino queda como visitante de la
    plataforma, no como cliente del negocio (ADR 0148).
  - El mismo folio no se cuenta dos veces.
- **Se concilia como las demás** (ADR 0185): con la tarifa por visita que captura el
  negocio y el reporte de pagos de Fitpass (CSV), emparejando por el folio.
- **Sin check-in automático, sin publicar clases y sin reservas desde su app**: eso pide
  la API de Fitpass.

## Consecuencias

- Si Fitpass da acceso a su API de socios, se agrega su validador y el check-in
  automático sin cambiar lo demás.
