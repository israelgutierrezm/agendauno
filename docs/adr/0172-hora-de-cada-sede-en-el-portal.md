# ADR 0172 — La hora de cada sede en el portal del cliente

Estado: Aceptado (2026-10-20). Completa el ADR 0166 (la app en la hora del negocio, no
en la del teléfono).

## Contexto

La app no trae un paquete de zonas horarias. Muestra las horas con el desfase con UTC
que le manda el API. La agenda del equipo y el Inicio ya reciben el de cada sucursal
(`inicia_local`, `termina_local` y `utc_offset_min`). El portal del cliente (`/mi/*`)
solo recibía el nombre de la zona (`zona_horaria`), que la app no sabe usar. Así que
mostraba todo con el desfase del negocio.

Con sedes en zonas distintas (Cancún y la Ciudad de México, o un país vecino), la app
le decía al cliente una hora equivocada para la clase o la cita de la otra sede. La web
no tenía el problema: usa `zona_horaria` con las zonas del navegador.

## Decisión

- Cada sesión que el portal lista trae la hora en la zona de su sede:
  - el calendario de clases (`GET /mi/clases`) y sus reservas (`GET /mi`) traen
    `inicia_local`, `termina_local` y `utc_offset_min`;
  - las órdenes con cita o clase, el historial, las reseñas pendientes, los movimientos
    de crédito (su clase) y el interés guardado traen `utc_offset_min`.
- Un solo cálculo para todos: `SesionTenantPresenter::horaLocal`. Es el que ya usaba
  la agenda: la zona de la sesión o, si no tiene, la del negocio. El desfase es el del
  momento en que empieza, así que el horario de verano queda bien.
- La app muestra cada reserva, clase, orden, movimiento e historial con el desfase de
  su sede. Si el API no lo manda (una versión anterior), usa el del negocio, como antes.
- En el detalle de una reserva o una clase, si el teléfono está en otra zona que la
  sede, se dice debajo de la hora: «Hora de Roma Norte».

## Consecuencias

- Los campos son nuevos y opcionales: una app anterior los ignora y una API anterior
  no los manda.
- La web sigue con `zona_horaria`.
