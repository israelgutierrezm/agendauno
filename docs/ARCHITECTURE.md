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

## Núcleo, Clases y Citas (ADR 0104)

Cada negocio es solo de clases o solo de citas: `estudios.modalidad`, guardada en el
control plane. Un solo código sirve a los dos modelos, con una frontera explícita:

- **Núcleo**: clientes, personal y permisos, sucursales, recursos y bloqueos; la
  agenda y su ocupación (`sesiones` con `tipo`, `VerificarAgendaTenant`); la reserva
  base (retención, política de cancelación, asistencia, reprogramar, reseñas); el
  comercio (productos, derechos con su ledger, órdenes, pagos, pasarelas), y lo
  transversal (outbox, comunicaciones, parámetros, auditoría). Membresías y bonos son
  núcleo: Citas también los vende.
- **Clases**: series, grupos, niveles, cupo por canal, lista de espera, lugares, pase
  de lista, check-ins, integraciones e importación de clases.
- **Citas**: horario de atención y disponibilidad, agendar (también sin cuenta),
  «cualquier profesional», márgenes, recursos por servicio, combos y cobro al agendar.

```
┌────────────────────── PLATAFORMA (control plane) ───────────────────────┐
│ estudios.modalidad: clases | citas (una sola por negocio)               │
│ tarifas_saas por modalidad · mediciones_uso · cargos_renta              │
│ log de cambios de modalidad                                             │
└────────────────────────────────────┬────────────────────────────────────┘
                                     │ modalidad y capacidades → web y app
                 ┌───────────────────┴────────────────────┐
                 ▼ negocio de clases                      ▼ negocio de citas
┌──────────── CLASES ────────────┐       ┌──────────── CITAS ─────────────┐
│ series, grupos, niveles        │       │ horarios de atención           │
│ cupo por canal, lista espera   │       │ disponibilidad, AgendarCita    │
│ lugares, pase de lista         │       │ márgenes, oferta_recursos      │
│ check-ins, importación         │       │ combos, cobro al agendar       │
│ rutas modalidad:clases         │       │ rutas modalidad:citas          │
└────────────────┬───────────────┘       └────────────────┬───────────────┘
                 │ solo hacia abajo                       │ solo hacia abajo
                 ▼                                        ▼
┌──────────────────────────────── NÚCLEO ─────────────────────────────────┐
│ Agenda: sesiones(tipo) · VerificarAgenda · recursos · bloqueos          │
│ Reserva base: reserva · retención · cancelación · asistencia · reseñas  │
│ Comercio: productos · derechos + ledger · órdenes · pagos · pasarelas   │
│ Identidad: personas · users/roles/permisos · sucursales                 │
│ Transversal: outbox · comunicaciones · parámetros · auditoría           │
└─────────────────────────────────────────────────────────────────────────┘
Permitido: Clases → Núcleo, Citas → Núcleo.
Prohibido: Clases ↔ Citas; el Núcleo importando código de Clases o de Citas.
```

Cómo se sostiene:

- `ModalidadNegocioTenant` es la única pregunta del dominio por la modalidad (y da el
  `tipo` de toda sesión nueva); nadie la deduce del giro ni de la oferta.
- Las rutas exclusivas llevan `modalidad:clases` o `modalidad:citas`
  (`ModalidadRequerida`, 403 `MODALITY_NOT_AVAILABLE`); las del núcleo no.
- La sesión manda `modalidad` y `capacidades`; la web y la app muestran según eso.
- Código nuevo: sin ramas `esCita` en el motor, lo de un modelo en su propio archivo,
  y el núcleo sin importar código de un modelo.
- `agendauno:revisar-modalidades` revisa (solo lectura) que los datos de cada negocio
  correspondan a su modalidad.

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
