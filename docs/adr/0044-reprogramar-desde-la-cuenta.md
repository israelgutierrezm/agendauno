# ADR 0044 — Cambiar el horario desde la cuenta del cliente

Estado: Aceptado (2026-09-26). Extiende el ADR 0038 (reprogramar) y usa el ADR 0042
(parámetros configurables).

## Contexto

Desde el ADR 0038 el negocio puede mover una cita o pasar a alguien a otra fecha de su
clase sin cancelar. El cliente, en cambio, solo podía cancelar y volver a reservar:
perdía el crédito o el pago si ya estaba dentro del límite de cancelación, y el
negocio recibía llamadas para algo que el cliente podía resolver solo. Cuánto margen
dar lo decide cada negocio, así que no puede quedar fijo.

## Decisiones

- **Qué puede cambiar**: su propia reserva confirmada o apartada (pendiente de pago),
  de una sesión programada.
  - Una cita pasa a otro horario libre del mismo servicio y profesional. Cuenta la
    atención del profesional, los bloqueos, los márgenes y el recurso. Su propio
    horario actual no estorba.
  - Una clase pasa a otra fecha de la misma clase, en la misma sede, con lugar.
- **Configurable por el negocio** (con valor de plataforma que fija el superadmin):
  - `reprogramar.horas_limite_cliente` (12 h): hasta cuántas horas antes del inicio.
    Después, solo el negocio.
  - `reprogramar.maximo_cliente` (1): cuántas veces por reserva. 0 = solo el negocio.
- **Mismas reglas que el negocio**: usa `ReprogramarTenant` (ADR 0038). No se vuelve a
  cobrar ni se mueve el crédito, y se avisa del cambio. La reserva cuenta sus cambios
  (`reservas.reprogramaciones_cliente`); los que hace el negocio no cuentan.
- **Dice por qué no**: `GET /mi/reservas/{id}/reprogramar` responde si puede, por qué
  no, hasta cuándo, cuántos cambios le quedan y las opciones (horarios del día pedido
  o fechas de la clase). El `POST` vuelve a validar todo; la pantalla no decide nada.

## Consecuencias

- Web ("Mi cuenta") y app muestran "Cambiar horario" en reservas confirmadas o
  apartadas; si ya no se puede, explican por qué.
- Cambiar a otro servicio o a otro profesional sigue siendo con el negocio.
