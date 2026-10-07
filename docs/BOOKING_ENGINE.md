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

## Waitlist

Support:
- FIFO
- optional priority tiers
- auto-book or timed offer
- expiration and next-candidate promotion

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
