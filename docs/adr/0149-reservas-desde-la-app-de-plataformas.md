# ADR 0149 — Reservas desde la app de Wellhub (y después TotalPass)

Estado: Aceptado (2026-10-12). Amplía el ADR 0148.

Pedido: cubrir lo que la competencia ya hace con Wellhub y TotalPass, incluido que sus
usuarios reserven las clases del negocio desde la app de la plataforma. Sigue en pie lo
del ADR 0148: quien llega así es cliente de la plataforma, no del negocio.

## Contexto

Con el ADR 0148 la visita se valida sola y ocupa lugar. Faltaba que el negocio publicara
sus clases en la plataforma. La Booking API de Wellhub funciona así:

- clases y horarios («slots») con sus lugares;
- avisos firmados de cada reserva, cancelación, cancelación tardía y check-in a una
  clase reservada;
- 15 minutos para aceptar o rechazar cada reserva;
- su Integration Setup API registra por gimnasio la dirección de los avisos y la llave con
  que se firman.

TotalPass tiene una Booking API parecida: ocurrencias con lugares, avisos de cada
reserva y 5 minutos para confirmarla. Queda para el bloque siguiente, sobre la misma base.

## Decisión

**Qué se publica.**

- En Integraciones, con la plataforma conectada, el negocio elige:
  - publicar o no;
  - la sucursal (el gimnasio de la plataforma es un lugar físico);
  - las clases grupales que se publican, cada una con su producto de Wellhub (se
    consultan en el momento; si no responde, se captura el ID).
- Se publican las sesiones programadas de esas clases desde ahora hasta
  `plataformas.dias_publicados` días por adelantado. Son parámetros del negocio con valor
  de plataforma, como todo límite (ADR 0042).
- `publicaciones_plataforma`: una por clase y plataforma, con el producto y el id de la
  clase allá.
- `horarios_plataforma`: una por sesión publicada, con su id allá, lo último que se le
  mandó y una huella del horario.
- Se le manda a la plataforma solo lo que cambió:
  - el horario completo (inicio en la zona del negocio, duración, instructor, sala,
    producto, ventana para reservar y plazo para cancelar);
  - o solo los lugares.
- Wellhub crea la clase una vez, con el ULID de la oferta como referencia. Cambiar el
  producto de una clase ya publicada se hace allá antes de guardarlo aquí.
- Lo que deja de publicarse se retira de la plataforma, y ella cancela sus reservas. Aquí
  quedan canceladas (`CLASE_RETIRADA`). Pasa cuando:
  - la sesión se cancela o se mueve;
  - la clase deja de publicarse;
  - se apaga la publicación o la integración.
- `agendauno:sincronizar-plataformas` (cada 5 minutos) mantiene todo al día. Después de
  cada reserva o cancelación de la plataforma, se le mandan al momento los lugares de esa
  clase.

**Los lugares que se ofrecen.**

- Salen de la regla única de cupo (`CupoDeSesionTenant`): lo que la plataforma aún puede
  tomar, sin lo apartado para otros canales (R20).
- Wellhub muestra `total_capacity - total_booked`. Se le manda:
  - el cupo de la clase;
  - como ocupado, todo lo que no puede tomar.
- Una clase sin cupo ofrece `plataformas.lugares_sin_tope` lugares.
- La ventana para reservar es la del negocio (`reservas.dias_apertura`,
  `reservas.minutos_cierre`).
- Cancelar sin costo vale hasta las horas de su política de cancelación, con el tope de
  24 horas de Wellhub.

**La reserva.**

- La persona sigue siendo visitante de la plataforma (`visitantes_plataforma`). Solo
  reservó: no tiene visitas todavía.
- Su reserva es un `lugar_plataforma` con `origen: reserva`. Ocupa cupo, no es una
  reserva del negocio y no consume créditos ni derechos.
- Se decide con la clase bloqueada antes de leer (invariante 8). Se rechaza con la
  categoría de Wellhub cuando:
  - la clase no se publicó aquí o se retiró (`CLASS_NOT_FOUND`);
  - la clase se canceló (`CLASS_HAS_BEEN_CANCELED`);
  - ya cerraron sus reservas (`CHECK_IN_AND_CANCELATION_WINDOWS_CLOSED`);
  - la persona ya tiene lugar en esa clase (`USER_IS_ALREADY_BOOKED`);
  - no queda lugar para la plataforma (`CLASS_IS_FULL`).
- El rechazo se guarda con su motivo, así la misma reserva recibe la misma respuesta.
- Los avisos entran por la bandeja del ADR 0148 (`eventos_plataforma`): se contestan al
  momento y se procesan después.
- Si no se le puede contestar a Wellhub, se reintenta mientras ella espera (15 minutos).
  Después ella la rechaza sola y el lugar se libera (`SIN_RESPUESTA`).
- Cancelada a tiempo o tarde, el lugar se libera y queda cómo se canceló.
- El check-in a la clase reservada (`checkin-booking-occurred`) se valida como cualquier
  check-in de Wellhub y va a esa clase. La reserva pasa a «asistió».
- Al terminar la clase, la reserva sin check-in queda «no asistió».

**Avisos.**

- Al encender la publicación se registra con Wellhub, con su Integration Setup API, la
  dirección de avisos del negocio (check-in y reservas).
- La llave con que firma los avisos es la del negocio. Si no capturó una, se genera y se
  guarda cifrada, solo si Wellhub la aceptó.

**Reportes → Plataformas.**

- Suma las reservas desde la app de las clases del periodo: por venir, asistieron,
  canceladas (y cuántas tarde), no asistieron y rechazadas. También van por plataforma.
- En el detalle de cada visitante aparecen sus reservas.

## Consecuencias

- La plataforma ve lo mismo que la agenda: el cupo es uno solo. Una reserva directa le
  quita lugar a la plataforma en la siguiente vuelta, y si llega tarde, la reserva de la
  plataforma se rechaza con la clase bloqueada. No hay sobreventa.
- Un negocio suspendido sigue publicando y recibiendo: sus clases siguen en pie.
- **Pendiente:**
  - TotalPass con su Booking API: ocurrencias, planes, aviso de reserva y confirmación en
    5 minutos.
  - Verificar con credenciales y ambiente de prueba de Wellhub. Las pruebas automáticas
    usan respuestas simuladas según su documentación pública.
  - Confirmar con Wellhub que el token del negocio sirve para su Integration Setup API.
    Si no, la dirección y la llave se le dan a mano, como en el ADR 0148.
  - La conciliación con lo que paga cada plataforma.
