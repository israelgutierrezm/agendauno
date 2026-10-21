# ADR 0188 — Servicios con precio publicado y referidos entre negocios

Estado: Aceptado (2026-10-20).

## Contexto

El comparativo recomendó cobrar aparte lo que el mercado ya acostumbra pagar aparte, sin
tocar la base (cobro por alumno activo, sin instalación, sin comisión y con todas las
sedes incluidas):

- un arranque acompañado con precio publicado, gratis con el plan anual;
- la app en tiendas con la marca del estudio, con un alta y una mensualidad;
- referidos con meses gratis, sin dinero en efectivo.

## Decisión

- **Precios publicados.** Los fija el superadmin como parámetros de la plataforma, en
  centavos de peso sin IVA:
  - `servicios.arranque_centavos`: 390,000.
  - `servicios.arranque_migracion_centavos`: 490,000.
  - `servicios.app_marca_alta_centavos`: 490,000.
  - `servicios.app_marca_mensual_centavos`: 99,000.

  `GET /precios` los publica en `servicios`, junto con `referidos.meses_gratis`.
- **Arranque acompañado.** En Mi suscripción → Complementos, como servicio de pago único.
  Pedirlo avisa a la plataforma (alerta `modulo_solicitado`, clave
  `servicio:{negocio}:{servicio}`) para agendar y cobrar. Es gratis con el plan anual.
- **App con la marca del negocio.** Es un módulo más (`app_marca`, ADR 0123/0140):
  - se pide como complemento y la plataforma lo enciende al publicar la app;
  - mientras está encendido, cada mes cerrado se emite su mensualidad como cargo
    `complemento` (clave `app_marca:AAAA-MM`), aparte de la renta y en pesos con IVA.
- **Referidos.**
  - Cada negocio tiene su código (`estudios.codigo_referido`), que se crea la primera vez
    que lo pide (`GET /renta/referidos`).
  - Quien se registra con `?ref=CODIGO` queda vinculado (`referido_por_id`). Nadie se
    invita a sí mismo.
  - Cuando el invitado paga su primer cargo de renta o de plan, los dos ganan un mes
    gratis (`beneficios_renta`, migración de plataforma 2026_10_20_000400). Es una sola vez
    por par.
  - Con cobro mes vencido, el siguiente cargo de renta sale sin cargo y dice por qué.
  - Con plan por adelantado (citas), se suma un mes a lo cubierto.

## Consecuencias

- La base del precio no cambia; lo nuevo se ve con su precio en la landing y en Mi
  suscripción.
- El arranque se cobra fuera del sistema (lo agenda y cobra ventas). Si se vuelve
  frecuente, se cobrará como los timbres.
