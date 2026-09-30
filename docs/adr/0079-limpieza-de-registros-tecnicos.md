# ADR 0079 — Limpieza de registros técnicos

Estado: Aceptado (2026-09-30).

## Contexto

Tres tablas guardan una fila por evento técnico y nada las borraba:

- `whatsapp_envios` (plataforma): el `wamid` de cada WhatsApp, para leer si se
  entregó o se leyó (ADR 0074).
- `verificaciones_whatsapp` (plataforma): los códigos del dueño, solo su huella
  (ADR 0070).
- `sesiones_tarjeta` (cada negocio): las sesiones de Stripe para autorizar una
  tarjeta del pago automático (ADR 0076).

Cada una deja de servir días después de crearse, y crecen con el uso.

## Decisión

- `agendauno:limpiar-registros`, diario a las 03:40 de CDMX, borra por lotes de 1000
  las filas creadas antes de su plazo. Los plazos son parámetros de plataforma que el
  superadmin ajusta en «Parámetros» → «Limpieza de registros»:

  | Parámetro | De fábrica | Rango | Por qué ese plazo |
  |---|---|---|---|
  | `limpieza.dias_envios_whatsapp` | 30 días | 7–365 | Meta avisa entrega y lectura en días. Pasado el plazo, sus avisos de ese mensaje se ignoran. |
  | `limpieza.dias_verificaciones_whatsapp` | 7 días | 2–90 | Los topes de códigos cuentan la última hora y el último día. |
  | `limpieza.dias_sesiones_tarjeta` | 30 días | 3–365 | La conciliación las revisa hasta 48 horas. |

- Las sesiones de tarjeta se limpian en cada negocio que tenga base, suspendido o no.
- No se borra historial del negocio: los mensajes (con su entrega y lectura), los
  pagos, las domiciliaciones, los avisos a los dueños y la bitácora se quedan.
- El comando resume cuántas filas borró de cada tabla.

## Consecuencias

- Las tablas técnicas quedan acotadas al uso de las últimas semanas.
- Si hiciera falta investigar un envío viejo, el estado de entrega sigue en el
  mensaje o en el aviso al dueño; solo se pierde el `wamid`.
- Otra tabla técnica que crezca sin límite se agrega a este comando con su propio
  parámetro.
