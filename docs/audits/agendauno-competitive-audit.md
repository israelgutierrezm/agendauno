# TurnoUno — Auditoría competitiva

**Fecha de corte:** 2026-09-16
**Repositorio:** `C:\Dev\turnouno` (rama `main`)
**Alcance:** inventario del código existente y clasificación de estado de los 45 requisitos del documento de producto (plataforma de operación de centros deportivos/wellness).
**Naturaleza:** revisión estática; **no se modificó código de producto**. Auditoría realizada con 5 agentes de exploración por dominio + inspección directa.
**Relación con trabajo previo:** este documento **no reemplaza** la auditoría de calidad/seguridad previa (`docs/audit/00-11` + `REMEDIATION.md`), la complementa con un ángulo **competitivo/de producto**. Reutiliza el diseño ya documentado en `docs/BOOKING_ENGINE.md`, `docs/MEMBERSHIP_ENGINE.md`, `docs/RESOURCE_ENGINE.md`, `docs/DOMAIN_MODEL.md` y los 17 ADR.

---

## Nota arquitectónica transversal (leer primero)

El sistema tiene **dos planos que coexisten**:

- **LEGACY** — esquema compartido (shared-DB), `tenant_id` explícito + `BelongsToTenant`, spatie/permission, Sanctum. Módulos `app/Modules/<M>/**`, migraciones `database/migrations/`.
- **TENANT (ACTIVO)** — **una base de datos por estudio** (data plane per-tenant). Modelos `*Tenant` en `app/Modules/Tenancy/`, RBAC propio (`CatalogoDePermisosTenant`), tokens propios (`TokenAccesoTenant`), migraciones `database/migrations/tenant/`, rutas `/api/v1/app/{estudio}/...` y por subdominio.

> **HALLAZGO MÁS IMPORTANTE DE LA AUDITORÍA:** la migración expand-migrate al plano per-tenant fue **incompleta**. El plano TENANT (activo) **perdió capacidades que el LEGACY sí tenía**: recursos con `recurso_id` en la sesión, asignación multi-staff con roles, recurrencia de agenda, reembolsos, renovación de ciclos de entitlement, webhooks firmados por proveedor, familias/tutelas y scope por sucursal en RBAC. Varias de estas "regresiones" son defectos de integridad (créditos colgados) o de seguridad (webhooks sin firma). **Antes de construir features nuevas hay que cerrar estas regresiones.**

---

# PARTE A — INVENTARIO

## 1. Arquitectura actual

- **Backend:** Laravel 13 / PHP 8.3, monolito modular (`app/Modules/`). Sanctum 4 (legacy), RBAC propio (tenant). Redis (predis 3.6). Pest 4 + Larastan L6 + Pint. Scramble (OpenAPI).
- **Multi-tenancy:** control plane central (`estudios`) + **data plane por tenant** (BD por estudio; SQLite en dev/test, MySQL en prod vía `TENANT_DB_DRIVER`). `GestorDeConexionTenant` reconfigura la conexión `tenant` por request; middleware `estudio.resolver → estudio.auth → puede:`. Resolución por ruta (`/app/{estudio}`) y por subdominio (`{slug}.turnouno.com`).
- **Frontend:** `apps/registro-web` (Vue 3 + TS + Vite + Pinia + Tailwind v4, **activo**), `apps/admin-web` y `apps/portal-web` (Vue 3, **legacy**), `apps/mobile` (Flutter, Riverpod + Dio, incipiente).
- **Config clave:** `config/turnouno.php` (`tenant_db_driver`, `dias_trial`, `dominio_base`), `config/database.php` (conexión `tenant`).
- **Coincide con la arquitectura objetivo del documento** (Laravel/PHP/MySQL/Redis/REST/tenant isolation/Vue 3+TS+Vite+Tailwind+Pinia/móvil, modular monolith). **No se propone cambiar el stack.**

## 2. Módulos existentes (17)

`Agenda`, `Asistencia`, `Autorizacion` (legacy), `Catalogo`, `Creditos`, `Hogares` (legacy), `Identity`, `Membresias`, `Ordenes`, `Organizaciones`, `Pagos`, `Personas`, `Platform`, `Portal`, `Recursos` (legacy), `Reservas`, `Tenancy`.

**No existen** como módulo: CRM, Automation/Automations, Communications, Reports/Analytics, Loyalty, Referral, Marketplace, Industry/Profiles, POS/Inventory, Workforce/Payroll, Audit. (Varios figuran como "Initial Domain Modules" en `apps/api/CLAUDE.md` pero **solo listados, no implementados**.)

## 3. Modelos y tablas

- **Control plane:** `estudios` (+ onboarding, publicación, facturación SaaS, `mediciones_uso`).
- **Data plane tenant** (16 migraciones en `database/migrations/tenant/`): `users`, `personas`, `documentos`/`tipos_documento`, `formularios`/`campos`/`respuestas`, `programas`/`actividades`/`niveles`/`ofertas`, `organizaciones`/`sucursales`, `sesiones` (+ `instructor_id`), `productos_comerciales`/`acuerdos`/`derechos`/`movimientos_credito`/`retenciones_credito`, `reservas`/`asistencias`, `ordenes`/`lineas_orden`, `configuraciones_pasarela`, `pagos`, `integraciones`, `checkins`.
- **Legacy** (47 migraciones): incluye además `marcas`, `instalaciones`/`recursos`, `plantillas_horario`/`reglas_recurrencia`, `asignaciones_sesion`, `hogares`/`tutelas`, refunds, etc. — varias **sin equivalente tenant**.
- **Ledger de créditos:** saldo **derivado** (`SUM(unidades)`), nunca almacenado — correcto (ADR-0009).

## 4. Relaciones principales (según `docs/DOMAIN_MODEL.md`, implementación parcial)

- Diseño: `Platform → Tenant → Organization → Brand → Branch → Facility → Resource`; `Household → Guardian → Dependent`; `Program → Activity → Offering → Session`; `Commercial Product → agreement → entitlement grants → usage`.
- **Implementado en tenant:** `Organizacion → Sucursal` (2 niveles; falta Brand/Region/Facility/Resource); `Programa → Actividad → Nivel → Oferta → Sesion`; `Producto → Acuerdo → Derecho → (ledger/holds)`; `Sesion ← Reserva → Asistencia`. **No en tenant:** Household/Guardian, Facility/Resource.

## 5. Endpoints

- **164 rutas** en `routes/api.php`, todas bajo `/api/v1`. Dos familias: legacy (`/…` con `auth:sanctum` + `tenant.*`) y tenant (`/app/{estudio}/…` + subdominio, con `estudio.resolver/auth/puede`). Webhooks entrantes de pasarelas (legacy dedicados por proveedor; tenant genérico `/webhooks/tenant/{estudio}/{proveedor}`). API versionada correctamente.

## 6. Jobs / queues

- **0 jobs de dominio.** Cola configurada (`queue:listen` en `composer dev`, predis). Solo **comandos**: `entitlements:generar-ciclos` (scheduler diario, opera sobre LEGACY), `facturacion:medir`, `datos:migrar-tenant`, `turnouno:sembrar-demo`. No hay jobs asíncronos de negocio (notificaciones, webhooks salientes, proyecciones).

## 7. Events / listeners

- **0 eventos de dominio.** Único `Event::listen` = propagación de `TenantContext` legacy a jobs (`TenancyServiceProvider`). **No** hay `BookingCreated/PaymentSucceeded/…` ni outbox, pese a `docs/adr/0004-event-outbox.md`.

## 8. Policies / permissions

- **Tenant (activo):** `CatalogoDePermisosTenant` — roles `propietario(['*'])/admin/recepcionista/instructor/miembro`, permisos **planos** por estudio; middleware `puede:`. Único scope: instructor limitado a sus sesiones (`AccesoSesionTenant`, ad-hoc).
- **Legacy:** spatie/permission + `ControlDeAcceso` con **scope por sucursal** (ADR-0008) y permisos más granulares (`pagos.reembolsar`, `roles.gestionar`) — **no portado al tenant**.

## 9. Integraciones

- **Pasarelas:** Stripe, OpenPay, Mercado Pago (clientes HTTP sin SDK, firmas verificadas en legacy; **en tenant solo Stripe tiene firma**). Ventanilla (comprobante) legacy.
- **Wellhub / TotalPass:** validación de check-in por HTTP (`ValidadorPartner`), tenant. No reparte aforo por canal.
- **Google SSO** (tenant, verificación de ID token).

## 10. Pantallas Vue

- **registro-web (activo, 16):** Landing, Registro, Activacion, Directorio, Entrar, Panel, Onboarding, Miembros, Ventas, Agenda, Documentos, Formularios, Pasarelas, Integraciones, Configuracion, MiCuenta.
- **admin-web (legacy, 10):** Agenda, Catalogo, Comprobantes, Familias, Membresias, MiAgenda, Miembros, Home, Login, Health.
- **No hay** vistas de Front Desk/recepción, CRM, Reportes, Automatizaciones, Fidelización, Mapa de recursos.

## 11. Flujos de usuario

- **Estudio:** registro público (slug) → activación → onboarding (9 pasos) → operación (miembros, ventas, agenda, documentos, formularios, pagos, integraciones).
- **Miembro (autoservicio):** perfil (derechos + reservas) → agenda → reservar/lista de espera → cancelar → comprar/pagar.
- **Instructor:** "Mi agenda" acotada a sesiones asignadas + asistencia.
- **Facturación SaaS del vendor:** medición de alumnos activos → cargo estimado (separado de los pagos del alumno).

## 12. Tests existentes

- **63 archivos** (~244 tests), Pest. **Bien cubierto (tenant):** booking/capacidad/waitlist/cancelación/no-show/idempotencia, aislamiento tenant, RBAC tenant, scope instructor, créditos/membresías, pagos/pasarelas, throttle login, correlation-id, contrato de error, check-ins partner, subdominio.
- **Brechas:** refund/dunning/renewal en tenant (features ausentes), conflicto de recurso, payment-failure e2e, waiver acceptance, **concurrencia real (2 hilos)** — solo pruebas secuenciales.

## 13. Funciones COMPLETAS (EXISTE Y CORRECTO)

- Aislamiento multi-tenant por BD (probado, falla-seguro 404).
- Concurrencia anti-sobreventa (lockForUpdate + transacción sobre conexión tenant) — R42.
- Ledger de créditos con saldo derivado (nunca almacenado) — núcleo de R2.
- Separación reserva/asistencia/acceso-partner con liquidación de hold idempotente — núcleo de R12.
- API versionada `/api/v1` — R40 (parte).
- Correlation-ID + logging por estudio — R44 (parte).
- Seguridad base: tokens hasheados, credenciales de pasarela cifradas, ULIDs (anti-IDOR), `$fillable`, CORS restringido, throttle de login, archivos en disco privado — R45 (parte).

## 14. Funciones PARCIALES (EXISTE PARCIALMENTE / REQUIERE REFACTOR)

R1 Booking (imperativo, sin salida estructurada), R2 Entitlements (ledger sin auditoría; expresividad limitada), R7 Waitlist (solo FIFO/WAITING), R8 Cancelación (hardcoded, sin config), R10 Membresías (estados mínimos, sin dunning/renovación tenant), R12 Check-in (sin OPEN_ACCESS ni política de acceso), R13 Front Desk (roster dentro de Agenda), R15 CRM (solo rol Lead), R17 Instructores (1 por sesión), R18 Multi-sucursal (2 niveles), R19 RBAC (roles planos), R20 Marketplace (solo check-in), R27 Documentos (sin versionado/aceptación), R36 Onboarding (9/10 pasos), R40 Webhooks entrantes (firma opcional), R41 Idempotencia (carrera menor), R44 Observabilidad.

## 15. Deuda técnica encontrada

- **Duplicación legacy/tenant** de motores casi idénticos (Reservas, Agenda, Membresías, Pagos) — mantener dos veces la misma lógica.
- **`GenerarCicloEntitlement` opera sobre legacy**, no sobre `DerechoTenant`: en el plano activo no hay renovación/expiración/rollover reales.
- Booking engine con **decisiones vía excepción** (no DTO estructurado) pese a que `docs/BOOKING_ENGINE.md` especifica explainability.
- `movimientos_credito` sin campos de auditoría (actor/source/balance_after/reserva/metadata); semántica en `descripcion` texto libre.
- `EstadoAcuerdo` (Activo/Pausado/Cancelado) y `EstadoPago` (Pendiente/Aprobado/Rechazado/Reembolsado) **insuficientes** para lifecycle real.
- `minimum-stability: dev` en composer.
- Recursos, multi-staff y recurrencia **no portados** a tenant.

## 16. Riesgos de integridad de datos

1. **[P0] `AgendaTenantController::cancelar` no cancela reservas ni libera holds** (`AgendaTenantController.php:111-117`): créditos retenidos "colgados" en sesiones canceladas. Regresión vs legacy.
2. **[P0] Webhook tenant sin firma para OpenPay/MercadoPago/ventanilla** (`WebhookTenantController.php:36-39`): confirmación de pago + fulfillment **forjables** con solo conocer `referencia_externa`. Stripe procesa sin secret si falta.
3. **[P1] `ReservasTenant::promover()` consume 1000 fijo** ignorando el costo por sesión personalizado (`ReservasTenant.php:192-193`): consumo de créditos incorrecto al promover de lista de espera.
4. **[P1] Carrera de idempotencia en reservas** (pre-check fuera de la transacción, `ReservasTenant.php:42-47`): duplicado concurrente → 500 en vez de devolver la reserva.
5. **[P1] Sin unique `(sesion_id, persona_id)`**: la no-duplicación depende del check bajo lock, sin defensa en BD.
6. **[P1] Renovación de ciclos no corre en tenant**: membresías recurrentes no renuevan/expiran en el plano activo.

## 17. Funcionalidades faltantes del documento (NO EXISTE)

R4 Mapa visual de recursos, R6 Reservas recurrentes (serie+preview), R9 Transferir/regalar reserva, R14 First-timer, R16 Automation engine, R21 POS/Inventario, R22 Promociones, R23 Referidos, R24 Loyalty, R25 Grupos/Enrollment, R28 Comunicaciones, R29 Reportes de negocio, R30 Class profitability, R31 Demand analytics, R32 Smart fill, R33 Churn engine, R34 AI Front Desk (capa), R35 Industry profiles, R37 Importación CSV, R38 Audit log, R39 Events/Outbox, R40 Webhooks salientes + API keys, R11/R17(payroll)/R26 en el plano tenant.

---

# PARTE B — MATRIZ REQUISITO × ESTADO

Estados: **OK** = existe y correcto · **PARC** = existe parcialmente · **REFACTOR** = existe pero requiere refactor · **BUG** = existe con errores funcionales · **NO** = no existe · **N/A** = no aplica.

## Dominio: Reservas y agenda

| # | Requisito | Estado | Archivos | Problema | Acción | Prio |
|---|---|---|---|---|---|---|
| R1 | Booking Policy Engine | REFACTOR | `Tenancy/Application/ReservasTenant.php:37-115`, `ResolverDerechoTenant.php`, `ReservaException.php` | Motor imperativo con `if/throw`; decisiones por excepción, sin DTO `{allowed,reason_code,rules_evaluated,credit_cost,…}` ni preview; no evalúa nivel/edad/conflicto/no-show/deuda/prioridad/invitados; duplicado legacy | Extraer `BookingPolicyEngine` único que devuelva `Decision` estructurada; reusar en ambos planos + endpoint `preview` | P0 |
| R5 | Scheduling / recurrence | NO (tenant) · PARC (legacy) | `Tenancy/…/AgendaTenantController.php:28-58`; legacy `Agenda/Application/GenerarSesiones.php` | Tenant solo sesiones sueltas; legacy solo recurrencia semanal; sin excepciones/feriados/override por instancia/serie↔occurrence | Portar plantillas/reglas + `serie_id` + excepciones a `migrations/tenant` | P1 |
| R6 | Reservas recurrentes (cliente) | NO | `Tenancy/…/MiTenantController.php:86-100` | Solo un `sesion_id`; sin serie ni preview de disponibles/créditos | Endpoint `reservas/serie` con preview (dry-run del motor R1) | P1 |
| R7 | Waitlist engine | PARC | `ReservasTenant.php:155,165-207`, `EstadoReserva.php` | Solo AUTO_FIFO + WAITING; sin OFFERED/ACCEPTED/EXPIRED, sin config, sin notificación, sin estrategias | Ampliar estados + tabla de config + notificación al promover | P1 |
| R8 | Cancelación / late / no-show | BUG + PARC | `ReservasTenant.php:123-159`, `AsistenciaTenant.php:40-43`, `AgendaTenantController.php:111-117` | **BUG P0:** cancelar sesión no libera holds; `horasLimite` hardcoded 6; sin motivo/strike/fee/tolerancia; no configurable | Portar cascade de `CancelarSesion`; política configurable por tenant/actividad/plan; registrar motivo/tipo | P0 (bug) / P1 (config) |
| R42 | Concurrencia | OK | `ReservasTenant.php:57-81`, `CreditosTenant.php` | Correcto; falta unique `(sesion_id,persona_id)` defensivo; SQLite serializa (validar en MySQL) | Añadir unique parcial; validar solape de horario | P2 |
| R41 | Idempotencia (reservas/créditos) | PARC | `reservas.idempotency_key`; `movimientos_credito` sin clave | Autoservicio no envía clave; pre-check fuera de transacción; ledger sin `idempotency_key` | Capturar unique-violation; clave en autoservicio y en asientos de ledger | P1 |

## Dominio: Comercio (entitlements, membresías, pagos)

| # | Requisito | Estado | Archivos | Problema | Acción | Prio |
|---|---|---|---|---|---|---|
| R2 | Entitlements + Credit Ledger | PARC | `Tenancy/Application/{LibroMayorTenant,CreditosTenant,MembresiasTenant}.php`, `Models/{MovimientoCreditoTenant,DerechoTenant}.php` | Saldo derivado **OK**; pero ledger sin actor/source/balance_after/reserva/metadata; solo 3 de 12 tipos; sin PROMOTION/TRANSFER/MAKEUP; derecho no expresa invitaciones/prioridad/descuento-POS/franjas/recursos/booking-window; multi-sede/actividad = 1 sola | Enriquecer `movimientos_credito` + enum de tipos; extender `derechos` a listas y políticas | P1 |
| R10 | Memberships / Subscriptions / Dunning | PARC/NO | `Membresias/EstadoAcuerdo.php`, `Pagos/EstadoPago.php`, `GenerarCicloEntitlement.php` | `EstadoAcuerdo` sin TRIAL/PAST_DUE/GRACE/SUSPENDED/EXPIRED; **renovación de ciclos no corre en tenant**; sin dunning; sin política reservas-ante-fallo | State machine de suscripción tenant; portar renovación a `DerechoTenant`; motor de dunning | P0/P1 |
| R11 | Refunds | NO (tenant) · PARC (legacy) | legacy `Pagos/Application/ReembolsarPago.php` | Tenant sin refund; legacy solo total/todo-o-nada, sin entidad Refund, sin devolución real ni conciliación | `ReembolsarPagoTenant` con parcial/proporcional + entidad Refund + llamada a pasarela | P0/P1 |
| R21 | POS e Inventario | NO | — | Sin productos retail/variantes/stock por sucursal/movimientos | Módulo POS separado de membresías (con stock por sucursal) | P2 |
| R22 | Promociones | NO | `OrdenesTenant.php:53-61` (sin descuento) | Sin cupones/descuentos/intro-offer/bundle; total = precio×cantidad | Motor de reglas de promoción versionadas | P2 |
| R41 | Idempotencia (pagos/webhooks) | PARC/BUG | `CobrarOrdenTenant.php:33-49`, `WebhookTenantController.php:36-52` | Cobro idempotente pero pre-check fuera de transacción + clave nullable (portal no la envía); **webhook no-Stripe sin firma** | Firma obligatoria por proveedor; mover pre-check dentro de transacción | P0 (webhook) |

## Dominio: Recursos, acceso y operación

| # | Requisito | Estado | Archivos | Problema | Acción | Prio |
|---|---|---|---|---|---|---|
| R3 | Resource Engine | NO (tenant) · PARC (legacy) | legacy `Recursos/Models/{Instalacion,Recurso}.php`, `ModoRecurso.php`; `Agenda/Models/Sesion.php` (`recurso_id`) | Tenant sin recursos (sesión solo capacidad); `tipo` sin tipar; sin taxonomía LOCATION/LANE/REFORMER/…; sin modos CAPACITY/ASSIGNED/POOL/OPEN/APPOINTMENT/COURT/GROUP | Enum `TipoRecurso` + modo de uso de la oferta; portar recursos + `recurso_id`/pool a tenant | P1 |
| R4 | Visual resource map | NO | — | Sin coordenadas/layout/editor/selección de spot/favorite | Feature nueva completa (modelo layout + editor + mapa cliente + selección al reservar) | P2 |
| R12 | Check-in / Access engine | PARC | `Tenancy/Application/{AsistenciaTenant,RegistrarCheckin}.php`, `Integraciones/*` | Reserva/asistencia/acceso-partner separados **OK**; pero sin OPEN_ACCESS (miembro propio), sin motor de política en el acceso, sin métodos QR/PIN/NFC/RFID | Entidad `Acceso` con `metodo` + evaluador de política de acceso reutilizable + modo open-access | P1 |
| R13 | Front Desk | PARC | `registro-web/src/views/AgendaView.vue` | Roster embebido en Agenda; sin panel del día, contadores, walk-in/alta rápida, cobro integrado, override auditado | Vista Front Desk (día×sucursal) con métricas + acciones rápidas + overrides con permiso/auditoría | P1 |
| R17 | Staff / Payroll | PARC/NO | tenant `SesionTenant.instructor_id`; legacy `AsignacionSesion`/`RolSesion` | Tenant: 1 instructor, sin sustitución/scheduled-vs-actual; **Payroll no existe** | Portar multi-staff+roles a tenant; modelo scheduled/actual/substitution; módulo Payroll | P1/P2 |
| R25 | Grupos / Enrollment | NO | catálogo `Programa→Actividad→Nivel→Oferta` | Todo requiere reservar cada sesión; sin Group (temporada) ni Enrollment ni asistencia auto por occurrence | Modelar `Grupo` + `Enrollment` con generación de asistencia por occurrence | P1 (natación/danza) |

## Dominio: Plataforma, seguridad y calidad

| # | Requisito | Estado | Archivos | Problema | Acción | Prio |
|---|---|---|---|---|---|---|
| R18 | Multi-sucursal | PARC | `migrations/tenant/…000007`; legacy `Organizaciones/Models/Marca.php` | Solo Org→Sucursal (+timezone); sin Brand/Region, sin currency/tax/pricing por sucursal, sin home location, sin consolidado; Marca no portada | Jerarquía Org→Brand→Region→Location; `sucursal_id` home en personas; overrides fiscales/moneda; reporting consolidado | P1 |
| R19 | RBAC / scopes | REFACTOR | `CatalogoDePermisosTenant.php:19-58`; legacy `Autorizacion/ControlDeAcceso.php` | Roles planos; sin scopes org/region/location/own (salvo instructor ad-hoc); faltan roles franquicia; overrides no auditables | Portar branch-scope; roles regional/location/sales/accounting; scopes explícitos; motivo+actor en overrides | P0/P1 |
| R26 | Familias / Household / Guardian | NO (tenant) · PARC (legacy) | legacy `Hogares/Models/Hogar.php`, `Personas/Models/Persona.php:80-97` | Tenant sin modelo; legacy solo datos, sin política guardián→dependiente (actuar en nombre de) | Portar Hogar/Tutela a tenant + capa de autorización guardián | P1 |
| R27 | Waivers / documentos | PARC/NO | `migrations/tenant/…000003`, `DocumentosController.php` | Documentos = subir+validar; **sin version/accepted_at/IP/hash/re-aceptación** (no es waiver) | Versionar tipos; tabla `aceptaciones_documento` (version/ip/hash); re-aceptación al cambiar versión | P1 |
| R38 | Audit log | NO | — (SEC-14 pendiente) | Sin registro append-only de operaciones sensibles (crédito/refund/override/permiso/precio) | Módulo `Audit` (actor/tenant/sucursal/action/entity/before/after/ip/correlation) cableado a servicios sensibles | P0/P1 |
| R39 | Events / Outbox | NO | `TenancyServiceProvider.php:73-96` (solo contexto) | 0 eventos de dominio; efectos colaterales inline; sin outbox (pese a ADR-0004) | Eventos de dominio + outbox transaccional per-tenant (habilitador de R16/R28/R40/R44) | P1 (habilitador) |
| R40 | API / webhooks | OK/PARC/NO | `routes/api.php:79,88-101` | API v1 **OK**; webhooks entrantes con firma opcional; **salientes firmados y API keys no existen** | Firma obligatoria en prod; subsistema de webhooks salientes (HMAC/retry/idempotency) sobre outbox; API keys con scopes | P1 |
| R41 | Idempotencia (plataforma) | PARC | ~48 archivos con `idempotency_key` | Buena cobertura; carrera menor en `crear()` | Capturar unique-violation; header `Idempotency-Key` estándar | P1 |
| R42 | Concurrencia (plataforma) | OK | `ReservasTenant`, `CreditosTenant`, `OrdenesTenant` | Correcto; `liberar()` sin lock (inocuo) | Ninguna crítica | P2 |
| R43 | Testing | OK (núcleo) | `tests/Feature/ControlPlane/*` (63 archivos) | Núcleo sólido; brechas: refund/dunning/renewal/resource-conflict/waiver/concurrencia real | Añadir tests al implementar cada feature; test de carrera de idempotencia | P1 |
| R44 | Observabilidad | PARC | `Http/Middleware/CorrelationId.php`, `ResolverEstudio.php:43` | Correlation-id + log por estudio **OK**; sin logs de decisión, métricas ni trazas | Logging estructurado de decisiones + métricas de negocio + trazas; audit (R38) para reconstrucción | P1 |
| R45 | Seguridad | Mixto | `AutenticacionTenant.php`, `ConfiguracionPasarelaTenant.php`, `AppServiceProvider.php:30-38` | Controles fuertes; brechas: **sin throttle autenticado**, firma webhook opcional, Sanctum sin expiración default, sin audit | Throttle en grupos `estudio.auth`; firma obligatoria; expiración de tokens; redacción de logs; audit | P0/P1 |

## Dominio: Crecimiento e inteligencia

| # | Requisito | Estado | Archivos | Problema | Acción | Prio |
|---|---|---|---|---|---|---|
| R9 | Transferir / regalar reserva | NO | — | Sin token de un solo uso/expiración/aceptación | Entidad `TransferenciaReserva` + flujo de aceptación (con/sin cuenta) | P2 |
| R14 | First-timer experience | NO | — | Sin detección de primera visita/compra ni badges | Contador de visitas derivado + badges en presenters de staff | P2 |
| R15 | CRM | PARC | `Personas/TipoPerfil.php` (rol `Lead`) | Solo el rol Lead; sin pipeline/lead-source/timeline 360 | Módulo CRM (pipeline configurable, lead_source, timeline) | P2 |
| R16 | Automation engine | NO | — | Sin trigger→condition→delay→action; depende de eventos/colas | Motor de automatización sobre event bus + colas | P2 |
| R20 | Marketplace / aggregator capacity | PARC | `Tenancy/Integraciones/*`, `checkins` | Solo check-in Wellhub/TotalPass; sin `ChannelCapacityRule` ni reparto de aforo ni booking_source | `ChannelCapacityRule` + `booking_source`/`revenue_source` + liberación progresiva | P2 |
| R23 | Referidos | NO | — | Sin referral tracking | Módulo referral (código/conversión/recompensa) | P2 |
| R24 | Loyalty / gamification | NO | — | Sin points ledger/streaks/badges | Módulo opcional desactivable (points ledger + eventos) | P3 |
| R28 | Comunicaciones | NO | — | Sin canal unificado EMAIL/WHATSAPP/PUSH/SMS ni templates ni estados | Módulo de comunicaciones (outbox + estados + colas + templates) | P1/P2 |
| R29 | Reportes / métricas | NO | `MedirAlumnosActivos.php` (solo billing SaaS) | Sin MRR/churn/occupancy/no-show/ARPU/LTV/booking-source de negocio | Capa de analítica de negocio (proyecciones desde eventos) | P2 |
| R30 | Class profitability | NO | — | Sin revenue/costo/margen por clase | Analítica de margen (requiere payroll + revenue por sesión) | P2/P3 |
| R31 | Demand analytics | NO | — | Sin métricas por horario ni recomendaciones | Métricas por franja + reglas explicables | P3 |
| R32 | Smart fill | NO | — | Sin identificación de candidatos | Segmentación por historial (sin auto-reserva) | P3 |
| R33 | Churn / risk engine | NO | — | Sin score de riesgo | Score rule-based explicable | P3 |
| R34 | AI Front Desk (capa) | NO | (base: `Application/*` services) | Sin capa de tools segura para IA | Tool/service layer autorizada y auditada sobre Application Services | P3 |
| R35 | Industry / business profiles | NO | `Estudio.php` (sin `industria`) | Sin concepto configurable (buena señal: **no hay forks `if industria`**) | `perfil_negocio` en Estudio + defaults/terminología/feature-flags por vertical | P1/P2 |
| R36 | Onboarding | PARC | `OnboardingController.php:23`, `OnboardingView.vue` | 9 pasos; faltan industria/recursos/importar; pasos guardan JSON sin materializar todo; sin quick-start | Añadir pasos industria/recursos/import; conectar cada paso a su módulo | P2 |
| R37 | Importación CSV | NO | (`datos:migrar-tenant` es migración, no import) | Sin importadores de clientes/membresías/créditos/instructores/productos con preview/errores/rollback | Importadores CSV con preview + errores por fila + idempotencia + rollback | P1/P2 |

---

## Reglas de anti-duplicación aplicadas en las acciones

- **No crear un segundo módulo de reservas/créditos/pagos:** extender los servicios `*Tenant` existentes y **unificar** con el legacy (extraer el motor común), no clonar.
- **Reutilizar lo que ya existe en legacy** al portar a tenant: `Recurso`/`ModoRecurso`, `AsignacionSesion`/`RolSesion`, `PlantillaHorario`/`ReglaRecurrencia`, `Hogar`/`Tutela`, `ControlDeAcceso` (branch scope), `ReembolsarPago`, `GenerarCicloEntitlement`.
- **Reutilizar diseño ya documentado:** `docs/BOOKING_ENGINE.md` (pipeline R1), `docs/MEMBERSHIP_ENGINE.md` (R2/R10), `docs/RESOURCE_ENGINE.md` (R3), ADR-0004 (outbox R39).

Ver el roadmap priorizado en **`turno-uno-roadmap.md`**.
