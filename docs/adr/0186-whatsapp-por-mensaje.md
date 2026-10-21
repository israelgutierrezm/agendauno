# ADR 0186 — WhatsApp por mensaje: precio, saldo y tope

Estado: Aceptado (2026-10-20). Completa los ADR 0069 y 0083.

## Contexto

Los avisos por WhatsApp salen del número de AgendaUno con plantillas aprobadas, y hoy los
paga la plataforma (ADR 0083). Meta cobra por mensaje desde julio de 2025. El comparativo
recomendó cobrarlos por consumo: la tarifa de Meta más un margen, con saldo prepagado y
un tope mensual que decide el negocio. La bandeja de WhatsApp e Instagram queda para
después: pide que la plataforma sea Tech Provider de Meta y que cada negocio conecte su
propia cuenta.

## Decisión

- **El superadmin decide si se cobra** (`whatsapp.cobro_por_mensaje`, apagado por
  omisión: hoy la plataforma los sigue pagando) y fija el precio por mensaje
  (`whatsapp.precio_mensaje_centavos`, en pesos, sin IVA) y los paquetes.
- **El saldo del negocio** vive en su base, con movimientos (`saldo_whatsapp`,
  `movimientos_whatsapp`, migración 000179), como los timbres.
  - Cada aviso que sale gasta un mensaje, una vez por aviso.
  - Una compra pagada (cargo `whatsapp` en pesos con IVA, por Stripe) suma su paquete
    una sola vez.
  - Las compras no son renta: no se avisan como renta vencida, no se domicilian y no
    cuentan en el embudo.
- **El negocio pone su tope** de mensajes al mes (`whatsapp.tope_mensual`, 0 = sin
  tope).
- **Si no hay saldo o se llegó al tope**, el aviso por WhatsApp se descarta con el motivo
  y no se reintenta. Los avisos por correo y en la app siguen.
- **Plantillas.** El negocio ve en Mi suscripción las plantillas aprobadas que puede
  mandar, con su texto y si las tiene encendidas. El texto es fijo y lleva el nombre del
  negocio; se encienden y apagan en Comunicaciones.

## Consecuencias

- Mientras el cobro esté apagado no cambia nada para los negocios.
- La tarifa de Meta para México (utilidad) ronda 0.0085 USD por mensaje, según fuentes de
  terceros. Antes de encender el cobro, hay que confirmarla con la tabla oficial de Meta.
