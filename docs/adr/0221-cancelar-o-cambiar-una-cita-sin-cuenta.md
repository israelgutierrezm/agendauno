# ADR 0221 — Cancelar o cambiar una cita sin cuenta

Estado: Aceptado (2026-10-26). Completa el agendado sin cuenta (citas públicas) y el
cambio de horario desde la cuenta (ADR 0044).

## Contexto

Una cita se agenda sin cuenta desde la página del negocio, pero para cancelarla o
cambiarla había que escribirle al negocio o crear una cuenta. El aviso decía «avísanos
para liberar tu horario». El dueño lo pidió para la V1 (no así la reseña ni la factura
sin cuenta).

## Decisión

- **Un enlace firmado en los avisos de la cita**: `{web}/cita/{slug}/{reserva}?f={firma}`.
  La firma es un HMAC con la llave de la aplicación sobre el negocio y la reserva
  (`EnlaceDeCitaTenant`). No se guarda en ningún lado ni viaja en el evento: se arma al
  escribir el aviso (marcador `{{enlace_cita}}`, solo en citas).
- Los textos por omisión de citas (confirmación, recordatorio de un día antes y cambio de
  horario) lo llevan: «Si no puedes asistir, cancélala o cámbiala aquí». Una migración
  cambia solo los que el negocio no editó.
- **Las reglas de siempre**: el enlace no da más que la cuenta. Cancelar usa la misma
  regla (hasta cuándo sin costo, qué pasa con el anticipo) y queda como cancelada por el
  cliente; cambiar el horario usa la del ADR 0044 (hasta cuántas horas antes y cuántas
  veces). La reprogramación usa el mismo controlador que la cuenta: solo cambia de dónde
  sale la reserva.
- **Abrir el enlace no cambia nada** (los filtros del correo abren los enlaces solos):
  cancelar pide confirmarlo en la página.
- Un enlace alterado, de otra reserva, de una clase o de otro negocio responde 404.
- Rutas públicas (con límite de peticiones, solo en negocios de citas):
  `GET /citas/enlace/{reserva}`, `POST …/cancelar`, `GET|POST …/reprogramar`, con `f`.

## Consecuencias

- Quien tenga el enlace puede cancelar o cambiar esa cita (como en el resto de los
  sistemas de agenda); por eso no expone datos de la persona, solo la cita.
- Si cambia la llave de la aplicación, los enlaces ya enviados dejan de servir.
- Migración del negocio `000202` (textos por omisión de citas).
