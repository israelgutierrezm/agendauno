# API

REST con JSON, versionada bajo `/api/v1`. Documentación OpenAPI generada con
Scramble en `/docs/api`. Rutas en `apps/api/routes/api.php`.

## Grupos de rutas

| Prefijo | Quién | Autenticación |
|---|---|---|
| `/api/v1/health` | Monitores (`?estricto=1` falla con 503) | — |
| `/api/v1/registro`, `/directorio`, `/legales` | Alta pública de negocios y directorio | — |
| `/api/v1/errores` | Errores de la web y la app para el monitoreo (ADR 0080) | — (tope por IP y diario) |
| `/api/v1/webhooks/tenant/{slug}/{proveedor}` | Pasarelas de cada negocio | Verificación con la pasarela |
| `/api/v1/webhooks/plataforma/{proveedor}` | Pasarela de la plataforma (renta) | Verificación con la pasarela |
| `/api/v1/plataforma/*` | Superadmin | `PLATFORM_ADMIN_TOKEN` |
| `/api/v1/app/{slug}/*` | Un negocio: equipo, profesionales y alumnos | Bearer del negocio |
| `{slug}.agendauno.mx/api/v1/*` | Lo mismo por subdominio | Bearer del negocio |
| `/api/v1/app/{slug}/integracion/*` | Terceros (solo lectura) | Llave de API con alcances |

Dentro de un negocio hay rutas públicas (login, activar, recuperar contraseña,
marca, escaparate, registro de alumno, citas sin cuenta, calendario iCal) y el
resto exige sesión y un permiso (`puede:…`). Ver `docs/AUTHORIZATION.md`.

## Autenticación

- `POST /app/{slug}/login` o `/auth/google` devuelve un token `{id}|{secreto}`; la
  base del negocio guarda solo el hash. Se manda como `Authorization: Bearer …`.
- La web y la app móvil usan el mismo token (no hay cookies de sesión ni Sanctum).
- Quien tiene varios roles entra con uno; `GET /yo` trae `rol` y
  `roles_disponibles`, y `PUT /yo/rol-activo` cambia de rol (ADR 0055).
- `POST /logout` revoca el token.

## Convenciones

- Identificadores públicos: ULID. Nunca el `id` interno.
- Dinero: `*_minor` entero + `moneda`. Créditos: unidades enteras (1000 = 1).
- País del negocio: ISO 3166-1 alfa-2 (`pais`, obligatorio en `POST /registro` y
  editable en `PUT /negocio/region`, que aplica país, moneda y zona juntos o ninguno);
  `lada`, solo dígitos (`52`, `57`, `1`). La sesión, el escaparate y
  `GET /citas/opciones` traen `estudio.pais` y `estudio.lada` (ADR 0103).
- Celulares: se mandan con lada como `+<lada> <número>` (`+57 3001234567`) y se guardan
  como llegan; al usarlos, el número junto a la lada se limpia como nacional (sin el 0
  de marcación nacional ni la lada repetida) y, sin «+», se completan con la lada del
  país del negocio. La unicidad del celular de una persona compara el número, no el
  texto.
- Fechas en ISO-8601 con zona; las horas se guardan en UTC y se muestran en la zona
  de la sucursal.
- Operaciones críticas (reservar, crear órdenes, pagar) aceptan `idempotency_key`
  en el cuerpo: repetir la petición devuelve el mismo resultado. Los webhooks son
  idempotentes por la referencia de la pasarela.
- Listas grandes paginan (`page`, `per_page`).
- Cada respuesta lleva `X-Correlation-ID`; se acepta uno entrante si es seguro.
- Límites de peticiones (`AppServiceProvider`, ADR 0102). En las rutas de un negocio
  el límite va después de resolver el negocio y la sesión, así que cuenta por usuario
  (o llave de API) de cada negocio, no por IP:
  - `login`, `recuperacion`: por correo y por IP;
  - `confirmar-contrasena`, `tenant` (120/min), `clima`, `whatsapp-panel-codigo`,
    `whatsapp-panel-verificar`: por usuario del negocio;
  - `negocio-publico` (marca, escaparate, agendar sin cuenta): por negocio e IP;
  - `calendario` (iCal): por enlace;
  - `publico` (registro, directorio, legales) y `plataforma` (superadmin, antes de
    validar su token): por IP.

## Errores

Los errores de dominio tienen un código estable (`TenancyException::codigo()`) que
`ApiExceptionRenderer` convierte en:

```json
{
  "code": "CAPACITY_FULL",
  "message": "La clase ya no tiene lugares."
}
```

`meta` aparece solo cuando el error trae datos para resolverlo.

Con el mismo sobre: una validación responde 422 `VALIDATION_FAILED` con
`meta.errors` por campo; sin sesión, 401 `UNAUTHENTICATED`; sin permiso, 403
`FORBIDDEN`; lo que no existe (o un negocio no disponible), 404 `NOT_FOUND`.

## Modalidad del negocio

Cada negocio es solo de clases o solo de citas, y la modalidad está guardada
(ADR 0104). Lo que la expone o la cambia:

- **Sesión.** El `estudio` de `POST /login`, `/auth/google`, `/activar`, `GET /yo` y
  `PUT /yo/rol-activo` suma `modalidad` (`"clases"` | `"citas"`) y `capacidades`
  (`{"clases": true, "citas": false}`); `perfil_config.modalidad` se conserva. `GET /yo`
  suma, en su nivel superior, `app: {version_minima}` (`APP_VERSION_MINIMA_APP`, por
  omisión `"0.0.0"`): una app más vieja pide actualizarse.
- **Registro.** `POST /registro` toma la modalidad del giro (`perfil_negocio`; sin giro,
  `general` → clases) y la devuelve en `data.estudio.modalidad`. Quien no encuentra su
  giro elige el «otro» de su modalidad: `general` («Otro negocio con clases») o
  `general_citas` («Otro negocio de citas»: cita, cliente, profesional).
- **Giro.** `GET /onboarding` trae `perfiles`: los giros que el negocio puede elegir,
  solo los de su modalidad. `PUT /perfil` (`{perfil_negocio}`) con uno de ellos cambia
  terminología y flags; con uno de la otra modalidad responde 422 `MODALITY_LOCKED`
  («Este negocio trabaja con citas; cambiar a clases lo hace AgendaUno.») con
  `meta.modalidad`.
- **Superadmin.** `GET /plataforma/estudios/{slug}` trae `modalidad` y
  `modalidad_cambiable` (sin sesiones ni reservas en su base).
  `PUT /plataforma/estudios/{slug}/modalidad` (`{modalidad, perfil_negocio?}`) responde
  200 con el resumen del negocio y `modalidad_cambiable`; 422 `MODALITY_IN_USE` si ya
  tiene sesiones o reservas, y 422 `VALIDATION_FAILED` si el giro es de la otra
  modalidad. Sin giro, conserva el suyo si encaja o toma `general` (clases) o
  `general_citas` (citas). Queda en la bitácora del negocio (`estudio.modalidad`).
- **Escaparate.** `GET /escaparate` suma `estudio.modalidad` y `estudio.capacidades`;
  `estudio.tiene_citas` sale de la modalidad (no de las ofertas) y
  `servicios[].agendable` solo es `true` en un negocio de citas (una clase de pago
  suelto no se agenda como cita).
- **Rutas exclusivas.** Las de un solo modelo llevan `modalidad:clases` o
  `modalidad:citas`; el negocio de la otra recibe 403 `MODALITY_NOT_AVAILABLE`
  («Esto no está disponible en un negocio de citas.») con `meta.modalidad` (la del
  negocio). Se revisa después de la sesión y del límite de peticiones (sin sesión, 401)
  y antes de buscar el recurso (no revela si existe).
  - Clases: `plantillas-horario*`, `grupos*`, `actividades/{a}/niveles`,
    `ofertas/{o}/capacidad-canal`, `capacidad-canal/{r}`, `importaciones/clases*`,
    `sesiones/oportunidades`, `sesiones/{s}/promover`, `reservas/{r}/aceptar`,
    `mi/reservas/{r}/aceptar`, `checkins`, `sesiones/{s}/checkins`, `miembros/padron`.
  - Citas: `horarios-atencion`, `disponibilidad`, `agenda/citas`, `mi/citas*`,
    `citas/opciones`, `citas/disponibilidad`, `citas/dias`, `POST /citas`,
    `reservas/{r}/historial`.
  - Del núcleo aunque digan «citas»: `POST /citas/pagar` y `GET /citas/orden/{orden}`
    (el enlace para pagar una sesión apartada también sirve a la clase de pago suelto).
    En un negocio de clases, la página web `/agendar/{slug}?pagar=<orden>` (y el
    regreso de la pasarela a `/agendar/{slug}?pago=…`) recibe 403 en `/citas/opciones`:
    toma la marca de `/escaparate` y solo usa estas dos rutas para mostrar y pagar.
- **Revisión.** `php artisan agendauno:revisar-modalidades [--estudio=slug]` (solo
  lectura) lista por negocio las sesiones del otro tipo, sus reservas y las ofertas de
  la otra forma; termina con error si alguno no cuadra.

## Agenda: contrato de clases y citas

Un negocio es solo de clases o solo de citas (ADR 0104): el `tipo` de cada sesión
sale de su modalidad. Cada sesión y cada reserva que devuelve la agenda conserva sus
campos de siempre y suma cuatro, con la misma regla en todas partes:

| Campo | Valor |
|---|---|
| `tipo` | `"clase"` o `"cita"`. El servidor no manda otro; un tipo desconocido es un error en la web y la app, no una clase. |
| `clase` | Objeto si `tipo` es `clase`; si no, `null`. |
| `cita` | Objeto si `tipo` es `cita` y tiene titular; si no, `null`. Una cita sin titular (cancelada o aún sin cliente) se lee por `estado`. |
| `ocupacion` | `{ocupados, capacidad, porcentaje}` en una clase con cupo; si no, `null`. `porcentaje` es entero de 0 a 100 (con sobrecupo, 100): es el que se muestra. |

`cita.estado_atencion` y `cita.estado_pago` los calcula el servidor a la hora de la
respuesta; la web y la app no los deducen:

- `estado_atencion`: `confirmada` (por atender), `sin_registrar` (terminó sin
  registrar si vino), `llego` (antes de empezar), `en_servicio`, `completada`,
  `no_asistio` o `cancelada`.
- `estado_pago`: `por_pagar` (apartada en línea, falta que el cliente pague),
  `por_cobrar` (su orden sigue pendiente), `pagada` o `null` (no lleva cobro: la toma
  con su plan).

`politica_reserva` de la oferta solo dice cómo se habilita la reserva (`pago` o
derecho); nunca si es cita.

### Agenda del equipo

`GET /sesiones` y la sesión que responden `POST /sesiones`, `POST /agenda/citas`,
`PUT /sesiones/{sesion}/instructor` y `POST /sesiones/{sesion}/cancelar`. Una clase:

```json
{
  "id": "01K8Z…",
  "tipo": "clase",
  "oferta": "Pole Nivel 1", "oferta_id": "01K8Y…", "oferta_lugares": 0, "oferta_precio_clase": null,
  "instructor": "Vale Ruiz", "instructor_id": "01K8X…", "sala": null,
  "sucursal": "Roma Norte", "sucursal_id": "01K8W…", "recurso_id": null,
  "serie_id": null, "serie_dias": null, "fecha_serie": null,
  "inicia_en": "2026-10-01T14:00:00+00:00", "termina_en": "2026-10-01T15:00:00+00:00",
  "asistencia_desde": "2026-10-01T13:30:00+00:00",
  "ocupa_desde": "2026-10-01T14:00:00+00:00", "ocupa_hasta": "2026-10-01T15:00:00+00:00",
  "zona_horaria": "America/Mexico_City",
  "capacidad": 12, "ocupados": 3, "en_espera": 1, "marcadas": 0,
  "estado": "programada",
  "clase": {
    "capacidad": 12, "ocupados": 3, "libres": 9, "en_espera": 1,
    "lugares": 0, "de_pago": false, "precio_minor": null
  },
  "ocupacion": { "ocupados": 3, "capacidad": 12, "porcentaje": 25 },
  "cita": null
}
```

`clase.capacidad` y `clase.libres` son `null` sin cupo; `lugares` es el número de
lugares numerados (0 = sin mapa); `precio_minor` es el precio de la clase suelta solo
si `de_pago`. Una cita (mismos campos de siempre; su `capacidad` es 1):

```json
{
  "id": "01K90…",
  "tipo": "cita",
  "oferta": "Corte de cabello", "capacidad": 1, "ocupados": 1, "en_espera": 0, "marcadas": 0,
  "estado": "programada",
  "clase": null,
  "ocupacion": null,
  "cita": {
    "estado_atencion": "confirmada",
    "estado_pago": "por_cobrar",
    "reserva_id": "01K91…",
    "cliente": "Marco Pérez", "cliente_id": "01K92…",
    "telefono": "+52 5512345678", "email": "marco@correo.mx",
    "estado": "confirmada",
    "asistencia": null, "retardo": false, "asistencia_automatica": false,
    "orden_id": "01K93…", "por_cobrar": true,
    "pago": null,
    "nota": null, "asiste": null
  }
}
```

`telefono` y `email` van en `null` sin el permiso `miembros.ver`; `pago` es
`{id, metodo, en_caja, corregible, anulable}` cuando ya se pagó.

### Cuenta del cliente (`/mi/*`)

`GET /mi/agenda` solo lista clases, y solo en un negocio de clases (en uno de citas
viene vacía): cada una lleva `tipo: "clase"`, `clase`, `cita: null` y `ocupacion`
junto a sus campos de siempre (`cobertura` incluida). `POST /mi/reservas` reserva solo
clases; en un negocio de citas responde 422 `SESSION_NOT_BOOKABLE`.

Sus reservas (`GET /mi/perfil` → `reservas[]`, y la reserva que responden
`POST /mi/reservas`, `POST /mi/citas`, `POST /mi/reservas/{reserva}/cancelar` y
`/aceptar`) suman `clase` (el bloque de su clase), `cita` y `ocupacion`. Su cita:

```json
{
  "id": "01K91…", "sesion_id": "01K90…", "tipo": "cita", "estado": "pendiente_pago",
  "oferta": "Corte de cabello", "sucursal": "Roma Norte", "mapa_url": null,
  "inicia_en": "2026-10-04T16:00:00+00:00", "termina_en": "2026-10-04T16:30:00+00:00",
  "instructor": "Leo", "asiste": null, "zona_horaria": "America/Mexico_City",
  "oferta_expira_en": null, "orden_id": "01K93…",
  "clase": null,
  "cita": {
    "reserva_id": "01K91…", "estado": "pendiente_pago", "asistencia": null,
    "orden_id": "01K93…", "nota": null, "asiste": null,
    "estado_atencion": "confirmada", "estado_pago": "por_pagar"
  },
  "ocupacion": null
}
```

En una reserva de clase, `clase` y `ocupacion` describen su clase y `cita` es `null`.

### Reprogramar

La forma la decide el `tipo` de la sesión de la reserva, no qué campos lleguen; lo
del otro tipo responde 422 `VALIDATION_FAILED`.

| Ruta | Cita | Clase | Respuesta |
|---|---|---|---|
| `POST /reservas/{reserva}/reprogramar` | `inicia_en_local` (obligatorio), `instructor_id` (opcional) | `sesion_id` (obligatorio) | `{tipo, reserva, estado, sesion_id, antes, ahora}` |
| `POST /sesiones/{sesion}/reprogramar` | `inicia_en_local`, `instructor_id` (opcional) | igual | `{tipo, sesion_id, antes, ahora}` |
| `GET /mi/reservas/{reserva}/reprogramar` | `?fecha=AAAA-MM-DD` → `zona_horaria`, `slots[]` (`inicia`, `termina`, `inicia_local`) | `sesiones[]` (`id`, `inicia_en`, `inicia_local`, `zona_horaria`) | siempre `{puede, motivo, tipo, restantes, hasta}` |
| `POST /mi/reservas/{reserva}/reprogramar` | `inicia_en_local` (obligatorio) | `sesion_id` (obligatorio) | `{tipo, reserva, sesion_id, antes, ahora, restantes}` |

### Reservas en una cita y eventos

- `POST /sesiones/{sesion}/reservas` y su `/preview` son del núcleo (sin modalidad),
  pero una cita es de una sola persona: `esperar: true` responde 422
  `SESSION_NOT_BOOKABLE` («Una cita no tiene lista de espera.», regla
  `sin_lista_de_espera`) y una segunda persona, 422 `SESSION_NOT_BOOKABLE` («Esta cita
  ya está ocupada.», regla `cita_libre`). Cancelar, dejar vencer o mover la reserva de
  una cita libera el horario del profesional; nunca se le ofrece a otra persona.
- `POST /agenda/citas`, `POST /mi/citas` y `POST /citas` agendan solo en un negocio de
  citas (en uno de clases, además del 403 de la ruta, el motor responde 422
  `SESSION_NOT_BOOKABLE`, «Este negocio trabaja con clases.»).
- Los eventos `reserva.*` y `asistencia.marcada` (mensajes automáticos y webhooks)
  llevan los datos de su sesión: `sesion_id`, `tipo` (`clase` | `cita`), `inicia_en`,
  `actividad`, `fecha`, `hora`, `sucursal` y `con`. Es aditivo: `reserva.creada`
  conserva `persona_id`, `estado` y `costo_unidades`; `asistencia.marcada`,
  `persona_id`, `estado`, `retardo`, `automatica` y `reserva_id`.
- El concepto de cobro de una orden por una sesión (página de pago de la pasarela,
  movimientos de caja) es «Cita» solo si la sesión es una cita; la clase de pago suelto
  es «Clase».
