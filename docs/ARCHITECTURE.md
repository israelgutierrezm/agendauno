# Arquitectura

## Estilo

Monolito modular (ADR 0001): una aplicación Laravel, una web y una app móvil sobre
la misma API. Sin microservicios.

```
apps/
├── api/      Laravel 13 — API REST, cola, programador
├── web/      Vue 3 — sitio, registro, panel del negocio, portal del alumno, superadmin
└── mobile/   Flutter — app del alumno, del profesional y del equipo
infra/        Docker de producción y Redis local
docs/         Arquitectura, ADRs, despliegue y verificación
```

## Backend (`apps/api`)

- `app/Modules/Tenancy/` — todo lo del negocio y el control plane que lo sostiene:
  - `Application/` — servicios de caso de uso (`ReservasTenant`,
    `ConciliarPagosTenant`, `RolesTenant`…), donde viven las reglas;
  - `Models/` — modelos Eloquent (conexión `tenant` o la del control plane);
  - `Http/` — controladores delgados, middleware (`ResolverEstudio`,
    `AutenticarTenant`, `puede:`), presenters;
  - carpetas de dominio (`Reservas`, `Creditos`, `Pagos`, `Pasarelas`,
    `Membresias`, `Comunicaciones`, `Parametros`…) con enums, excepciones y objetos
    de valor;
  - `Database/GestorDeConexionTenant` — elige la base del negocio.
- `app/Modules/Platform/` — operación de la plataforma: `Operacion` (latidos,
  alertas, respaldos, simulacro de restauración, verificación de producción y de
  concurrencia) y `Legales` (aviso de privacidad y términos versionados).
- `app/Console/Commands/` — comandos `agendauno:*`, programados en
  `routes/console.php`.
- `app/Support/` — piezas técnicas compartidas (renderer de errores, traits).

Los controladores validan y delegan; las reglas de negocio no viven en
controladores, ni en Vue, ni en Flutter.

## Eventos y efectos secundarios

```
transacción en la base del negocio
→ evento de dominio
→ outbox transaccional (eventos_outbox)
→ agendauno:despachar-outbox (cola)
→ correos, push, webhooks salientes, automatizaciones
```

Cada trabajo lleva el negocio en su carga y corre dentro de su base.

## Almacenamiento

- MySQL: control plane + una base por negocio (READ COMMITTED, ADR 0052).
- Redis: caché, colas, locks y límites de peticiones (con prefijo por negocio).
- Almacenamiento compatible con S3: respaldos y archivos.

## Web (`apps/web`)

Vue 3 + TypeScript + Vite + Tailwind v4 + Pinia + Vue Router + vue-i18n. Una sola
app para el sitio comercial, el registro de negocios, el panel de cada negocio, el
portal del alumno y el superadmin. Terminología por negocio con i18n (ADR 0049).

La web solo presenta: estado local, interacción y UX. Lo que oculta por permisos es
comodidad; la API decide.

## Móvil (`apps/mobile`)

Flutter con Riverpod y Dio; consume la misma API versionada. Ver `docs/MOBILE.md`.
No reimplementa reglas críticas: pide a la API y muestra el motivo si se niega.

## Operación

- `X-Correlation-ID` en cada petición y en sus logs.
- Latidos del programador y la cola; `/api/v1/health?estricto=1` para monitores.
- Alertas por correo agrupadas (ADR 0051).
- Respaldos por negocio y de la plataforma, con simulacro de restauración.
- Actualizar con punto de corte y abrir solo si atiende (ADR 0054).
- Detalle en `docs/DESPLIEGUE.md`.
