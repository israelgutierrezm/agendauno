# Booking Engine

## Goal

Answer:

Can this participant reserve this session right now, using which entitlement, and why?

## Pipeline

1. Resolve participant.
2. Resolve tenant/branch.
3. Validate booking window/priority.
4. Validate session state.
5. Validate participant eligibility.
6. Validate age/level/discipline rules.
7. Resolve eligible entitlements.
8. Validate branch/time restrictions.
9. Validate outstanding-balance policy if enabled.
10. Validate capacity and required resources.
11. Validate duplicate/conflicting booking rules.
12. Select entitlement source.
13. Acquire transactional capacity lock.
14. Place entitlement hold/consume according to policy.
15. Create booking.
16. Commit.
17. Emit BookingCreated.

## Explainability

Return stable error codes such as:
- BOOKING_NOT_OPEN
- MEMBERSHIP_REQUIRED
- ENTITLEMENT_EXHAUSTED
- LEVEL_REQUIRED
- AGE_NOT_ELIGIBLE
- CAPACITY_FULL
- RESOURCE_UNAVAILABLE
- OUTSTANDING_BALANCE_BLOCK
- ALREADY_BOOKED

The member sees the same decision before confirming:
`GET /mi/sesiones/{sesion}/antes-de-reservar` (`AntesDeReservarTenant`, ADR 0119) runs the
booking window, `evaluar()`, plan coverage and the cancellation policy that would be frozen
in the booking, and answers `reserva`, `lista_espera` or `no_disponible` with the main reason
and the action that helps. It reads only; booking revalidates under the session lock.

## Booking windows

Example:
- Premium member: 14 days
- Standard member: 10 days
- Package: 7 days
- Drop-in: 24 hours

Booking priority and pricing are separate concerns.

## Concurrency

Capacity must be protected by database transactions and locks.

Do not:
read capacity → check in PHP → insert
without transactional protection.

Use idempotency keys for booking creation.

One lock order for the whole engine (ADR 0230): orders → payment → session → reservation →
professional → resource → entitlement → hold. Read the `sesion_id` without a lock, lock the
session, lock the reservation and revalidate (`ReservasTenant::bloquearConSesion`). Paths that
take several of these give 3 attempts to the transaction; a concurrency error that runs out of
attempts (SQLSTATE 40001, MySQL 1213, a lock wait timeout) answers **409
`CONCURRENT_CONFLICT`**, never 500. `CreditosTenant::confirmar` and `perder` lock the entitlement
and then the hold, like `retener`: `saldo_posterior` in the ledger is read under that lock.

If the entitlement chosen without a lock no longer has balance when the hold is taken, `crear`
resolves again without it (and re-checks its booking cap); with none left, the insufficient
balance is answered as is.

Importing bookings or appointments does not hold locks across the file: the review (preview and
the first step of importing) runs without locks in a transaction that always rolls back
(`RevisionSinCandados`) and what passes is saved in batches of 20. See ADR 0230.

## Spots (one rule, ADR 0116)

`CupoDeSesionTenant` is the only place that decides how many spots a class has left:

- Reservations that hold a spot: `confirmada`, `ofrecida` (a waitlist offer holds the spot
  while the person decides) and `pendiente_pago` (until it is paid or the per-minute job
  cancels it). `en_espera` does not hold a spot.
- Channel rules (R20) set aside their unused spots for their channel until
  `liberar_horas_antes` before the start; other channels don't see those spots.
- No capacity means no limit (`null`).

The booking engine (under the session lock), the public page, the member agenda, the staff
agenda, reception, rescheduling and onboarding all use it. Lists load the counts in batch
(`withCount(CupoDeSesionTenant::conteos())` + `cargar()`), never one query per class.
Reading the public count never books anything: the engine revalidates on confirm.

## Cancellation

Policies may vary by:
- tenant
- branch
- activity
- offering
- membership/product

Example:
>= 6 hours: release entitlement
< 6 hours: forfeit entitlement

Cancelling a session (the business cancels the class): every active booking, its guests
(`reservado`, the monthly pass comes back) and, once committed, its platform spots: Wellhub and
TotalPass through `cancelarPorElNegocio` (retried by `agendauno:sincronizar-plataformas` if the
platform did not answer; after the class starts they are only closed here); Fitpass, which has
no API, goes to a «to cancel in its panel» list (`GET /integraciones/fitpass/por-cancelar`).
Cancelled sessions never turn a platform spot into `no_asistio`. Cancelling a booking still to
be paid, or its whole session, closes the open payment attempt at the gateway after commit. See
ADR 0230.

## Waitlist

Support:
- FIFO
- optional priority tiers
- auto-book or timed offer
- expiration and next-candidate promotion

The waitlist always comes first (ADR 0230). Every released spot is offered to it: a cancellation,
a higher capacity on one date or on the series, removing the capacity altogether, and a channel
rule reaching its release time. `agendauno:expirar-ofertas` sweeps future classes with people
waiting and free spots, which catches what nothing announces. Under the session lock, a new
booking (`crear`, `reservarConOrden`, `moverA`, guests and platform bookings) first offers what is
free to the waitlist and only then evaluates: it never takes a spot someone is waiting for and
was not offered. `promover` skips a candidate whose channel has no spot left: spots set aside for
another channel are not given away (R20).

## Closed days (ADR 0230)

A closed day (`excepciones_horario`) belongs to one branch or to the whole business
(`sucursal_id` null), one per date and branch. A branch is closed by its own days and by the
business's (`ExcepcionHorarioTenant::queAplicanA`): appointment availability, `cabeEnHorario`,
series generation, class imports and agenda occupancy all use it. Whole-business days are created
or removed only by someone who sees every branch; a branch's, by someone who can operate it.
Cancelling what a closed day affects touches only that branch.

## Branch time zone (ADR 0230)

A session stores its instant in UTC and a copy of the zone. Changing the zone of a branch with
future sessions requires confirmation (`confirmar_zona`; without it, 409 with counts and nothing
changes) and re-anchors them: same wall clock, so 07:00 stays 07:00 in the new zone. The copied
zone says whether a session was already re-anchored, so it is idempotent. Reminders are redone and
whoever holds a spot gets `reserva.reprogramada`. Working hours and series store local time and do
not move; blocks of the branch and its rooms store instants and are re-anchored too. Times sent as
`inicia_en_local` are always read in the zone of the branch.

## Appointments (citas)

A session is either an open `clase` (bookable by anyone entitled) or a private
`cita` materialized for one person from an availability slot. Citas are hidden
from other members and the public storefront, cannot be booked or waitlisted by
anyone else, and release the professional's slot when their booking ends
(cancelled or unpaid). Paid services booked online start `pendiente_pago` and
expire; booked by the business they start confirmed with an order to collect at
the counter. See ADR 0018.

A business works only with classes or only with appointments (ADR 0104), so the
type of every new session is the business modality (`clases` → `clase`, `citas`
→ `cita`), on every path: creating a session, series, imports and booking an
appointment. `SesionTenant` fills it from `ModalidadNegocioTenant` when the
caller does not set it; there is no `clase` default. `politica_reserva` only says
how a booking is enabled (`entitlement` or `pago`), never whether it is a cita:
a paid offer in a classes business is a class paid per session, not an
appointment, and `AgendarCitaTenant` refuses any offer there
(`SESSION_NOT_BOOKABLE`, «Este negocio trabaja con clases.»). Series generate
nothing in an appointments business.

The appointment rules live in the engine, for every caller (staff roster, the
member's account, the public page):
- `evaluar` / `crear`: a cita has no waitlist (`esperar=true` →
  `SESSION_NOT_BOOKABLE`, rule `sin_lista_de_espera`) and no second active
  booking (`SESSION_NOT_BOOKABLE`, rule `cita_libre`); `crear`,
  `reservarConPago` and `reservarPorCobrar` re-check it under the session lock.
- `promover` never offers a cita to anyone.
- When a booking leaves a session (cancel, unpaid expiry, declined or expired
  offer, `moverA`) the same strategy applies: a cita releases the professional's
  slot (a leftover waitlist entry is cancelled with it); a class offers the place
  to its waitlist. `moverA` into a cita requires it to be free.

`reserva.*` and `asistencia.marcada` events carry the session data
(`DatosDeSesion`: `sesion_id`, `tipo` `clase|cita`, `inicia_en`, `actividad`,
`fecha`, `hora`, `sucursal`, `con`), additive to their previous payload. The
payment concept of an order for a session reads «Cita» only when the session is
a cita; a class paid per session reads «Clase».

A client may book with "any available professional" (no `instructor_id`): the
slots are the union of every professional who works at that branch that day, and
the booking goes to the least busy one that day (then by name). Each attempt is a
normal booking under that professional's lock; if the slot was just taken, the
next candidate is tried. See ADR 0062.

Whether a client-booked paid appointment is held until paid online depends on the
business setting `citas.pago_en_linea_obligatorio` and on having an active online
gateway. Otherwise it is confirmed on booking with its order left to collect
(online later or at the branch). See ADR 0065.

Booking from the public page without an account (`reservas.sin_cuenta`): typing an email does not
prove it is yours, so that path always pays (it never uses what that person prepaid) and its
`citas.maximo_por_pagar` counts only appointments booked without an account. See ADR 0230.

An appointment only moves to someone who attends appointments and gives that service (ADR 0217),
through any path: rescheduling or `PUT /sesiones/{s}/instructor`, which takes the session lock,
refuses to leave it without a professional and notifies with the new one. It cannot be transferred
to another person. A late payment does not reconfirm a booking if that person already has another
live booking in that class or an overlapping appointment: it stays `PAGO_TARDIO` to reconcile.
See ADR 0230.
