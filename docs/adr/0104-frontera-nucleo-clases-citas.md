# ADR 0104 — Frontera núcleo / clases / citas y modalidad excluyente

Estado: Aceptado (2026-10-07). Reemplaza en parte el ADR 0018 (negocios mixtos: «el
tipo vive en la sesión, no en el tenant») y precisa los ADR 0019, 0088, 0090, 0091 y
0092.

## Contexto

Una revisión de arquitectura preguntó si Clases y Citas podrían separarse en dos
productos sin reescribir. La respuesta fue «sí, con ajustes», y señaló lo que hacía
falta decidir antes de tener más negocios y más datos:

- **El negocio no guardaba su modalidad.** Se deducía del giro en cada lectura, y
  `PUT /perfil`, que el propio negocio usa para cambiar de giro, cambiaba con él la
  modalidad y la métrica del cobro del SaaS.
- **Nadie había decidido si existen negocios mixtos.** El ADR 0018 y el cobro los
  permitían («el tipo vive en la sesión»); la web y la app solo funcionaban con una
  modalidad.
- **`politica_reserva = pago` tenía dos significados**: «servicio de cita» o «clase
  que se paga suelta». Cada parte del código lo leía a su manera (una clase grupal de
  pago mostraba «Agendar cita» en la página pública).
- **La frontera solo existía en los clientes.** El servidor dejaba usar cualquier
  ruta con el permiso; había siete formas distintas de saber si un negocio «es de
  citas».
- **Las reglas de la cita vivían en un controlador**: el equipo podía poner a una
  segunda persona en espera de una cita privada.
- **La documentación se contradecía**: PRODUCT y DOMAIN_MODEL decían que la modalidad
  «solo decide el cobro y qué se ve primero»; los ADR 0088 a 0092 ya la trataban como
  excluyente.

El dueño decidió: **no se permiten negocios con clases y citas a la vez.**

## Decisión

### Cada negocio es solo de clases o solo de citas

- `estudios.modalidad` (`clases` | `citas`, obligatoria) es la única fuente.
  `Estudio::modalidad()` la lee; el dominio pregunta a `ModalidadNegocioTenant`
  (`modalidad()`, `esCitas()`, `tipoSesion()`, `capacidades()`), nunca al giro ni a la
  forma de una oferta.
- **El giro solo da el valor inicial** al registrarse (`ModalidadServicio::paraPerfil`).
  Los negocios existentes se llenaron con esa misma deducción.
- **El negocio cambia de giro solo dentro de su modalidad** (`PUT /perfil`): eso cambia
  la terminología, los flags y las sugerencias. Un giro de la otra responde 422
  `MODALITY_LOCKED` («Este negocio trabaja con citas; cambiar a clases lo hace
  AgendaUno.»). `GET /onboarding` trae `perfiles`: los giros que puede elegir.
- **Solo el superadmin cambia la modalidad**, y solo mientras la base del negocio no
  tenga sesiones ni reservas, igual que la moneda solo cambia antes de cobrar
  (`PUT /plataforma/estudios/{slug}/modalidad`; si no, 422 `MODALITY_IN_USE`). Con
  ella cambian el giro (el que se elija de la nueva modalidad o, si el suyo no encaja,
  `general` o `estetica`) y la métrica del cobro. Queda en el log de la plataforma
  (`plataforma.estudio.modalidad`) y en la bitácora del negocio (`estudio.modalidad`).
  El detalle del negocio en el superadmin trae `modalidad_cambiable`.
- La revisión de «sin sesiones ni reservas» no bloquea la base del negocio mientras
  se cambia. Se acepta: lo hace el superadmin, antes de que el negocio opere, y
  `agendauno:revisar-modalidades` detecta lo que no cuadre.
- Modalidad, giro (`PerfilNegocio`), capacidades, plan del SaaS y flags siguen siendo
  conceptos distintos (principio 12 de CLAUDE.md).

### El tipo de toda sesión sale de la modalidad

- Toda sesión nueva toma `tipo = ModalidadNegocioTenant::tipoSesion()` (clases →
  `clase`, citas → `cita`): al crearla, al generar una serie, al importar y al agendar
  una cita. Ya no hay un `'clase'` por omisión.
- **Las ofertas no tienen columna de tipo**: el tipo de una oferta es el de la
  modalidad de su negocio. `politica_reserva` vuelve a significar solo cómo se habilita
  la reserva (con un derecho o pagando), nunca si es cita.
- Las reglas de la cita viven en el motor: una sesión de cita no admite lista de espera
  ni una segunda reserva activa (`SESSION_NOT_BOOKABLE`), y `moverA` libera el origen
  igual que cancelar.
- La etiqueta «Cita» en cobros sale de `sesiones.tipo`, no de que la orden tenga
  sesión.

### El servidor niega lo exclusivo

El middleware `modalidad:clases` / `modalidad:citas` (`ModalidadRequerida`) va en las
rutas exclusivas de cada modelo y responde 403 `MODALITY_NOT_AVAILABLE` al negocio de
la otra modalidad. Las rutas del núcleo (sesiones, reservas, asistencia, catálogo,
cobros, miembros…) no lo llevan. La web y la app ocultan; el servidor decide.

### Capacidades que manda el servidor

La sesión (`/yo`, el login) trae `modalidad` y `capacidades` (`{clases, citas}`), y el
escaparate `estudio.modalidad` y `estudio.capacidades`. El código nuevo de la web y de
la app pregunta por la capacidad; no la deduce de los pasos del onboarding, de las
ofertas ni del giro. `perfil_config.modalidad` se conserva para lo ya publicado.

### Qué es núcleo, qué es Clases y qué es Citas

| | Contenido |
|---|---|
| **Núcleo** | Clientes (`personas`), personal y permisos (`users`, roles, alcance; instructor y profesional son el mismo rol), sucursales, recursos, bloqueos y cierres. Agenda y ocupación: `sesiones` (con `tipo`), `VerificarAgendaTenant`, `VerificarRecursoTenant`. Reserva base: reserva, retención, política de cancelación congelada, asistencia (ADR 0101), reprogramar, cancelar y reseñas; una cita *es* una reserva sobre una sesión. Comercio: productos, acuerdos, derechos con su ledger y retenciones, órdenes, pagos y pasarelas; **membresías, bonos y créditos son núcleo**, porque Citas los vende como bonos y membresías (ADR 0091). Transversal: outbox, comunicaciones, parámetros, auditoría, mostrador e inventario, lealtad. |
| **Clases** | Series (`plantillas_horario`, `generar-agenda`), grupos e inscripciones, niveles, cupo por canal, lista de espera y ofertas de lugar (`expirar-ofertas`), lugares numerados, pase de lista y check-ins, integraciones (Wellhub, TotalPass), importación de clases, oportunidades de llenado, padrón de alumnos. |
| **Citas** | Horario de atención y disponibilidad (`horarios_atencion`, `CalcularDisponibilidadTenant`), `AgendarCitaTenant` y `OpcionesCitaTenant`, «cualquier profesional», márgenes, recursos por servicio (`oferta_recursos`), combos (`oferta_incluidos`), cobro al agendar, página pública para agendar (`PublicoCitasController`), historial de la cita, parámetros `citas.*`. |

### Dependencias permitidas

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

### Reglas para el código nuevo

- **No agregar ramas `esCita` / `esCitas` al motor** (`ReservasTenant`,
  `ReprogramarTenant`, `VerificarAgendaTenant`, el ledger). Las que ya existen no se
  multiplican; lo que difiere por modelo va en un servicio propio del modelo.
- **Lo nuevo de un modelo va en su propio archivo**: su servicio, su controlador y sus
  rutas, con `modalidad:clases` o `modalidad:citas`. Cada ruta nueva se clasifica:
  núcleo o exclusiva.
- **El núcleo no importa código de un modelo.** Si necesita algo de Clases o de Citas,
  es un punto de extensión que el modelo registra.
- **Se pregunta por la modalidad del negocio** (`ModalidadNegocioTenant`) o por la
  capacidad, nunca por el giro ni por la forma de la oferta (`politica_reserva`,
  `ofertas.modalidad`).
- **Los eventos `reserva.*` y `asistencia.*` llevan `tipo`** (`clase` | `cita`). Salen
  por webhooks a terceros; el campo es aditivo.
- Los límites nuevos de cada modelo son parámetros con su prefijo (`citas.*`,
  `agenda.*`, `reservas.*`).

### Contrato de la app

Antes de publicar la app en las tiendas, las respuestas de la agenda quedan
simétricas (detalle en `docs/API.md`):

- `GET /sesiones` y lo que la app consume de `/mi/*` conservan sus campos y suman
  `tipo`, `clase` (objeto o `null`), `cita` (objeto o `null`) y `ocupacion`
  (`{ocupados, capacidad, porcentaje}` o `null`). En citas, el servidor calcula
  `cita.estado_atencion` y `cita.estado_pago`.
- Un `tipo` desconocido es un error explícito en la app, no una clase.
- `/yo` trae `app.version_minima` (`APP_VERSION_MINIMA_APP`, por omisión `0.0.0`):
  una app instalada más vieja pide actualizarse en lugar de leer contratos que ya no
  entiende.

### Revisar lo que ya existe

`php artisan agendauno:revisar-modalidades [--estudio=slug]` (solo lectura) muestra, por
negocio, las sesiones del otro tipo, sus reservas y las ofertas con la forma de la
otra modalidad (individuales en clases, grupales en citas; las privadas valen en
ambas). Termina con error si algún negocio no cuadra. Se corre después de desplegar
esta decisión y se corrige a mano lo que señale (datos de la heurística del ADR 0018,
migración tenant 000051).

### Cobro del SaaS

Sigue a la modalidad guardada: alumnos activos en clases, profesionales activos en
citas (ADR 0019, 0094). Como ya no hay negocios mixtos, se cierra el pendiente del
ADR 0018 sobre su cobro. La regla de «personas atendidas fuera de cita» de la tarifa
de citas ya publicada no se quita (las tarifas son versionadas), pero un negocio de
citas ya no crea clases que la activen.

## Consecuencias

- `PUT /perfil` ya no mueve la modalidad ni el cobro; el giro y la modalidad dejan de
  estar atados después del registro.
- Quien quiera ofrecer clases y citas necesita dos negocios: dos bases, cuentas
  separadas (el correo es único por negocio) y sin detección de choques de agenda
  entre ellos. Es la consecuencia aceptada de la decisión.
- Las pruebas que armaban un negocio de citas cambiando el giro ahora lo registran con
  un giro de citas (`estudioConSesion($slug, $correo, 'barberia')`).

## Cómo se separaría a futuro

- Si algún día se vende ambos modelos a un negocio, la modalidad pasa a ser un
  conjunto de capacidades con fechas en el control plane; el middleware ya niega por
  modalidad y solo cambiaría su pregunta.
- A los negocios puros no se les migra nada: cada uno ya tiene su base y sus sesiones
  ya tienen su tipo.
- Lo demás es partir código sin tocar datos: controladores por modelo con las rutas
  actuales como fachada, `ReservasTenant` con una estrategia por tipo, carpetas
  `Agenda/{Nucleo,Clases,Citas}` con reglas de dependencias en `ArchitectureTest`, y
  la web y la app organizadas por capacidad.
