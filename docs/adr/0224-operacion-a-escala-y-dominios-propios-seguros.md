# ADR 0224 — Operación a escala y dominios propios seguros

Estado: Aceptado (2026-10-26). Cierra los pendientes de backend y operación de la
revisión para la V1.

## Contexto

La revisión del backend encontró trabajo pesado en el programador, tablas que crecían
sin límite y huecos con los dominios propios (ADR 0189): el outbox timbraba en FacturAPI
y devolvía pagos dentro del programador; el envío de mensajes encolaba dos trabajos por
negocio cada minuto aunque no hubiera nada; los eventos publicados y los mensajes
enviados nunca se borraban; reCAPTCHA no revisaba el dominio ni la acción (y la consola
de Google no admite los dominios de cada negocio); y con Cloudflare, un dominio propio
llegaba directo a Traefik y podía inventar `CF-Connecting-IP` para saltarse los límites
por IP.

## Decisión

- **Llamadas externas del outbox en la cola**: facturar una cita al pagarse y devolver
  pagos (al cancelar el negocio o con anticipo) son listeners en cola (`ShouldQueue`,
  3 intentos), que solo se encolan para sus eventos (`shouldQueue`). El trabajo lleva el
  negocio en su carga, como todos.
- **Envío de mensajes solo con pendientes**: cada mensaje nuevo suma en `cola_mensajes`
  (control plane) y `agendauno:enviar-mensajes` solo encola a quien tiene pendientes o
  lleva 10 minutos sin revisión (red de seguridad para un envío cortado). Reanudar una
  campaña también avisa.
- **Purga con plazos largos** (parámetros de plataforma): los eventos ya publicados
  (`limpieza.dias_eventos_publicados`, 365) y los mensajes que ya no están por salir
  (`limpieza.dias_mensajes_enviados`, 365). Lo pendiente no se borra.
- **reCAPTCHA**: el token debe venir de un dominio de la plataforma o del dominio propio
  activo de un negocio, y de la acción que se esperaba (`registro`, `cita`, `captacion`,
  `interesados`, `demo`). En la consola de Google se quita la lista de dominios.
- **Dominios propios**: sus reglas de Traefik apuntan al puerto 81 de nginx
  (`agendauno-web-propios`), donde la IP real siempre sale de `X-Forwarded-For`; los
  dominios de la plataforma siguen en el 80 (con Cloudflare, `CF-Connecting-IP`). En un
  dominio propio no se ofrece «Entrar con Google» (Google no admite esos orígenes).
- Verificar un token de Google tiene su tiempo de espera; el asistente responde con
  claridad cuando el modelo se niega (`stop_reason: refusal`).
- Los `.env.example` documentan `TRAEFIK_ENTRADA`, `DOMINIOS_PROPIOS_SERVICIO`,
  `DOMINIOS_PROPIOS_DESTINO`, `DOMINIOS_PROPIOS_DIR` y las `ARCHIVOS_S3_*`.

## Consecuencias

- Al actualizar, los servidores con dominios propios toman el servicio nuevo con la
  siguiente pasada de `agendauno:dominios-propios` (cada 10 minutos).
- Con la cola detenida tampoco salen las facturas automáticas ni las devoluciones (ya lo
  vigila el latido de la cola).
