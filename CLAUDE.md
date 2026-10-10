# AgendaUno — Claude Code Project Instructions

## Mission

Build AgendaUno, a commercial multi-tenant SaaS for businesses that work with
classes or appointments: studios (pole, pilates, yoga, dance), academies, gyms,
swimming schools, barbershops, salons, spas and clinics.

The product MUST use one configurable core. Business profiles (`PerfilNegocio`)
adapt terminology, flags and the service mode (classes or appointments). Do not
create separate applications or duplicated domain logic per industry.

## Technology

Backend (`apps/api`):
- Laravel 13, PHP 8.3+
- MySQL: one control-plane database plus one database per business
  (SQLite files per business in development and tests)
- Redis (cache, queues, locks, rate limiting)
- REST API under `/api/v1`
- Own bearer tokens per business (no Sanctum, no Spatie)
- Queues, jobs, events, listeners, outbox, scheduler
- S3-compatible storage for backups and files

Web (`apps/web`, one app): Vue 3, Composition API, TypeScript, Vite, Tailwind CSS
v4, Pinia, Vue Router, vue-i18n. It serves the marketing site, business
registration, the business panel, the member portal and the superadmin.

Mobile (`apps/mobile`): Flutter, Riverpod, Dio; feature-first (`lib/features/*`),
repository + service data layer. One codebase, two official apps (ADR 0111): Android
flavors `agendauno` (default) and `turnouno`; `ProductoApp.actual` in Dart. Requests
carry `X-App-Producto`; white-label apps (AgendaUno only) are built from
`configuraciones/marca_blanca/<negocio>.json` and carry `X-App-Negocio`.

Infrastructure: Docker (`infra/produccion`, `actualizar.sh` / `volver.sh`),
GitHub Actions CI (API with MySQL, web, mobile).

## Architectural Style

Modular monolith. Backend code lives in:
- `apps/api/app/Modules/Tenancy/` — everything that belongs to a business (data
  plane) and the control-plane entities it needs (`Estudio`, SaaS billing):
  `Application/` (services), `Models/`, `Http/` (controllers, middleware,
  presenters), plus domain folders (`Reservas`, `Creditos`, `Pagos`, `Pasarelas`,
  `Membresias`, `Comunicaciones`, …) with enums, exceptions and value objects.
- `apps/api/app/Modules/Platform/` — platform operations: `Operacion` (heartbeats,
  alerts, backups, restore drills, production and concurrency checks) and
  `Legales` (versioned privacy notice and terms).
- `apps/api/app/Console/Commands/` — `agendauno:*` commands (scheduled in
  `routes/console.php`).

Do NOT:
- create microservices prematurely;
- put all models in `app/Models` or business logic in controllers;
- put critical business logic in Vue or Flutter;
- add industry checks such as `if ($perfil === 'barberia')`: use profile config;
- add business-specific code;
- use EAV for core entities;
- use float for money or credits;
- maintain balances without ledgers;
- expose sequential database IDs in public APIs (use ULIDs);
- perform heavy processing synchronously;
- trust frontend permission checks;
- create migrations before the domain for the requested module is understood.

Simple CRUD does not need ceremonial DDD.

## Mandatory Domain Principles

1. The business (`Estudio`, tenant) is the SaaS security and billing boundary; each
   one has its own database.
2. Organizations (brands) and branches (`sucursales`) live inside a business.
3. Person (`PersonaTenant`) != User (`Usuario`) != Member != Instructor.
4. Purchaser != participant.
5. Memberships, packs, extras and makeups grant entitlements (`derechos`).
6. Booking validates eligibility, booking window, entitlements, capacity and
   resources.
7. Credits use an auditable ledger (`movimientos_credito`) with holds
   (`retenciones_credito`).
8. Reservations must be concurrency-safe: lock the parent row (session,
   professional, entitlement, order) inside the transaction, then read.
9. Payments are provider-agnostic (Stripe, Mercado Pago, OpenPay behind
   `PasarelaTenant`); webhooks are idempotent and missed ones are reconciled.
10. Domain events go through the outbox for asynchronous side effects.
11. Configuration, policies and commercial plans that affect historical behavior
    are versioned; business limits are parameters (per business or platform),
    not hardcoded.
12. Feature flags, plan entitlements, business capabilities and business profiles
    are distinct concepts.

## Public Identifiers

- internal PK: BIGINT UNSIGNED
- public ID: ULID (`HasPublicId`)

Never let public API consumers depend on internal auto-increment IDs unless
approved.

## Money

Never use float. Use `amount_minor BIGINT` + `currency CHAR(3)` (in this codebase:
`*_minor` columns and `moneda`). Example: 89900 MXN = MXN 899.00.

## Credits

Never store only `credits_available`. Use ledger entries and holds. Fractional
credits use scaled integer units: 1000 units = 1 credit, 500 = 0.5.

## Authentication

- Business users (web and mobile): bearer tokens issued per business
  (`AutenticacionTenant`, `TokenAccesoTenant` in the business database, only the
  hash is stored). Google SSO per business.
- Active role per session (ADR 0055): a user with several roles enters with one
  (`personal_access_tokens.rol_activo`) and switches with `PUT /yo/rol-activo`.
- Superadmin: `PLATFORM_ADMIN_TOKEN`.
- Third-party integrations: API keys with scopes; OAuth only when delegated access
  is actually required.

## Authorization

RBAC per business plus scope:
- System roles in code (`CatalogoDePermisosTenant`: propietario, admin,
  recepcionista, instructor, miembro) and custom roles per business (`roles` table,
  ADR 0057), both resolved by `RolesTenant`.
- Permissions look like `miembros.ver`, `agenda.gestionar`, `reservas.gestionar`,
  `asistencia.marcar`, `pagos.reembolsar`, `roles.gestionar`. Every permission
  used in a route must be in `CatalogoDePermisosTenant::catalogo()`.
- Check with the `puede:` middleware or `$usuario->puede()`; both use only the
  ACTIVE role. Use `rolesEfectivos()` only for what the person is (bookable
  professional, owner rules), never for permissions.
- Scope: branch scope (`ResolverAccesoTenant`) and instructor scope
  (`AccesoSesionTenant`).
- Nobody grants what they do not have: custom roles and role assignments must fit
  inside the actor's active permissions.
- Do not authorize by role name in domain code when a permission check is possible.

## Multi-tenancy

- Control plane database: `estudios`, SaaS rates, rent charges, invoices,
  platform alerts, legal documents.
- One database per business (MySQL `tenant_*` in production; SQLite files in
  `storage/tenants` in development and tests). Migrations for businesses live in
  `database/migrations/tenant` and run with `agendauno:migrar-estudios`.
- `GestorDeConexionTenant` points the `tenant` connection at one business;
  `ejecutarEn()` always restores the previous one. Jobs carry the business in their
  payload.
- A business is resolved by path (`/api/v1/app/{slug}`) or subdomain
  (`{slug}.agendauno.mx`).
- MySQL runs in READ COMMITTED (ADR 0052); `agendauno:verificar-concurrencia`
  proves the locking with real concurrent processes.
- Defense in depth: isolation tests, authorization, audit log (`auditorias`).

## Testing Rules

Every critical domain change requires tests. Critical suites: business isolation,
membership activation, entitlement grants, credit consumption, booking
capacity/concurrency, cancellation/refund of entitlement, waitlist promotion,
payment webhook idempotency and reconciliation, authorization scopes, attendance.

Prefer feature tests around real domain flows over excessive mocking. Locally the
API suite runs with SQLite (`DB_CONNECTION=sqlite DB_DATABASE=":memory:"`); CI runs
it with MySQL and also runs the concurrency check.

API tests run on a fixed clock (`Tests\TestCase::AHORA`, 2026-10-01 06:00 Mexico City)
so literal dates in tests don't expire. A test that needs another moment uses
`travelTo`; tests that compare with file timestamps on disk call `travelBack`. Test
helpers are global functions: give them unique names (a duplicate breaks the whole
suite, not just one file).

## Workflow for Claude Code

Before coding a new domain module:

1. Read relevant docs under `/docs` and the ADRs in `docs/adr`.
2. Summarize the requested behavior.
3. Identify domain invariants.
4. List affected entities and boundaries.
5. Identify concurrency/idempotency/security risks.
6. Propose implementation plan.
7. Only then modify code.
8. Add/update tests.
9. Update docs and add an ADR (next number in `docs/adr`) when architecture changes.

For risky architectural decisions, stop and explain trade-offs before committing
to a design.

Naming: domain terms in Spanish (`Reserva`, `Sesion`, `Derecho`, `Sucursal`),
framework and technical terms in English. Commands are `agendauno:*`; the config
file is `config/agendauno.php`.

Do not generate dozens of placeholder classes. Prefer small vertical slices that
are fully implemented and tested.
