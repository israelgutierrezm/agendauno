# Membership and Entitlement Engine

## Principle

Memberships do not directly answer every booking rule.

Commercial products grant entitlements.

Sources of entitlements:
- recurring membership
- limited membership
- class pack
- add-on/top-up
- promotion
- administrative adjustment
- make-up credit
- guest benefit

## Both service modes (ADR 0091, ADR 0104)

The engine is core, not a classes feature. A business works only with classes or
only with appointments, and both sell plans through the same products, agreements,
entitlements, ledger (`movimientos_credito`) and holds (`retenciones_credito`):

- **Classes:** memberships, class packs, passes, extra classes, top-ups.
- **Appointments:** a session bundle (bono, e.g. five massages) is a pack whose units
  are visits; a membership gives recurring services for a fee. A service is booked
  either by paying its price or with an entitlement that covers it, never both: the
  client account lists covered services as "with your bundle", and the booking holds
  and consumes units exactly like a class booking.

A plan with no offerings assigned covers every offering of the business; since a
business has only one mode, that never mixes classes and appointments.

## Examples

### Unlimited
Pole Beginner: unlimited
Flexibility: unlimited

### Limited
General classes: 8 per entitlement cycle

### Mixed
Pole: 8
Conditioning: 4
Flexibility: unlimited

## Entitlement properties

- scope
- allowance
- valid_from
- valid_until
- reset policy
- rollover policy
- expiration policy
- branch restrictions
- activity restrictions
- level restrictions
- time restrictions

## Cycles

BillingCycle and EntitlementCycle are separate concepts.

Support:
- calendar month
- anniversary period
- fixed billing day
- fixed term
- pack expiration

## Rollover

Policies:
- none
- full
- capped
- expires-after-N-days

## Add-ons

Never edit a four-class membership into six classes just because the member buys two extra.

Create a separate purchase/grant and ledger entries.

Implemented (ADR 0050): an add-on is its own agreement + entitlement (`extra_de_id`)
tied to the member's current package; it shares the package's classes and expiry
and is consumed after it.

## Validity and class scope (ADR 0050)

- Validity: none, N days, N months to the same date, or until the end of the
  (Nth) month. Computed at sale in the business time zone; the last day counts.
- A plan may apply only to some offerings (`producto_ofertas`), copied to the
  entitlement at sale (`derecho_ofertas`).
- Members see a statement per plan (`GET /mi/planes`): included, extras, each use
  (attended, no-show, late cancel, upcoming), refunded, expired, available.

## Credit ledger

Ledger entry examples:
+4000 monthly allowance
-1000 Pole Beginner
+2000 add-on purchase
-500 Open Pole

Use scaled integers, never float.

## Holds

Booking may place entitlement units in HOLD state.

Possible states:
- available
- held
- consumed
- released
- forfeited
- expired

Cancellation rules determine whether a hold is released or forfeited.
