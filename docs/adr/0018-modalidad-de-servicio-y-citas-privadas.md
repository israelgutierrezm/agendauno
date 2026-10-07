# ADR 0018 — Modalidad de servicio (clases/citas) y citas privadas

Estado: Aceptado (2026-09-22). Reemplazado en parte por el ADR 0104 (negocios mixtos:
la modalidad ya no se deriva del perfil, se guarda y es excluyente).

## Contexto

AgendaUno sirve a dos tipos de negocio con experiencias y cobros distintos:
estudios de CLASES con cupo (pilates, pole, yoga, gym, natación) y negocios de
CITAS 1 a 1 con un profesional (barbería, estética, salón, spa, salud). La
regla del proyecto prohíbe ramas por industria (`if ($industria === …)`), y la
revisión del sistema encontró que una cita se guardaba como una sesión más:
aparecía en la agenda de otros miembros y en el escaparate público, cualquiera
podía reservarla o esperar su lugar, y al cancelarse o no pagarse quedaba una
"clase" de cupo 1 huérfana que bloqueaba el horario del profesional.

## Decisiones

- **Modalidad a nivel tenant, derivada del perfil.** *(Reemplazado por el ADR 0104: se
  guarda en `estudios.modalidad`; el giro solo da el valor inicial.)* `ModalidadServicio`
  (`clases` | `citas`) se deriva de `perfil_negocio`; el giro solo elige el
  default y el dominio decide por modalidad. Se expone en
  `perfil_config.modalidad` (login, `/yo`, perfil, escaparate, registro). La UI
  adapta agenda, menú, terminología y onboarding a ella. El cobro SaaS seguirá
  a la modalidad (alumnos activos vs profesionales activos).
- **Tipo por sesión.** `sesiones.tipo` (`clase` | `cita`, `TipoSesionTenant`).
  Una cita es una sesión PRIVADA materializada para una persona
  (`AgendarCitaTenant`). Un negocio de clases puede ofrecer citas (sesiones
  privadas) y viceversa: el tipo vive en la sesión, no en el tenant. *(Reemplazado
  por el ADR 0104: no hay negocios mixtos; el tipo de toda sesión sale de la
  modalidad del negocio.)*
- **Privacidad.** Las citas no se listan en `/mi/agenda`, el escaparate ni las
  oportunidades de llenado; nadie más puede reservarlas ni esperar su lugar
  (`SESSION_NOT_BOOKABLE`). El staff sí las ve, con su titular
  (`cita{cliente, estado, asistencia, orden_id, por_cobrar}`).
- **Liberación.** Cuando la reserva de una cita termina (cancelada o sin pagar
  a tiempo), `ReservasTenant::liberarCita` cancela la sesión: el profesional
  recupera el hueco. Cancelar una reserva también cancela su orden pendiente.
- **Dos formas de agendar un servicio de pago.** En línea (cliente):
  `PendientePago` + orden; expira si no paga en la ventana. Por el negocio
  (recepción/teléfono, `POST /agenda/citas`): `Confirmada` + orden por cobrar en
  caja (`reservarPorNegocio`); no expira. Pagar la orden (en línea o
  `liquidar`) cierra el cobro.
- **Defaults por modalidad.** En citas, una oferta nueva nace como
  pago-para-reservar de 30 min, y el paso "horarios" del onboarding exige el
  horario de atención de un profesional (no una clase).

## Consecuencias

- Relleno de datos: las sesiones de cupo 1, sin serie y con reservas se
  marcaron como citas (migración tenant 000051).
- `pendiente_pago` cuenta como lugar ocupado en agenda, roster y ocupación.
- Pendiente: medir profesionales activos para el cobro por modalidad, y que
  los clientes "guest" de citas no cuenten como alumnos facturables. (El cobro de
  los negocios mixtos queda cerrado por el ADR 0104: no existen.)
