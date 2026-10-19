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
| `/api/v1/plataforma/entrar`, `/entrar/verificar` | Entrada del superadmin (ADR 0126) | Correo, contraseña y `PLATFORM_ADMIN_TOKEN`; luego el código por correo |
| `/api/v1/plataforma/*` | Superadmin | Sesión del operador (en producción el token solo no basta) |
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
- **Perfiles del menú (ADR 0162).** El mismo `estudio` trae `con_equipo` (hay más de
  una persona del equipo) y `plataformas_bienestar` (en clases: tiene Wellhub o
  TotalPass conectado o ya recibió visitantes). La web y la app ofrecen lo de un
  equipo y el reporte de plataformas solo con ellos; el servidor no niega nada por
  esto.
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
  `por_cobrar` (su orden sigue pendiente, o pagó el anticipo y falta el saldo; ADR
  0161), `pagada` o `null` (no lleva cobro: la toma con su plan).

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

`GET /mi/sesiones/{sesion}/antes-de-reservar` (solo clases) dice, sin apartar nada, lo que
pasará al reservar (ADR 0119): `resultado` (`reserva`, `lista_espera` o `no_disponible`),
`codigo`, `motivo` y `accion` si no se puede, `plan` (`nombre`, `ilimitado`, `vence`,
`disponible_despues`), `unidades`, `de_pago`, `cancelacion` (`limite`, `horas_limite`,
`penaliza_tarde`, `cancela_tarde`), `sucursal` (`nombre`, `mapa_url`), `requisitos` y
`que_llevar`. Las reservas suman `plan`, `unidades`, `cancelacion` (null en lista de
espera) y `que_llevar`; la vista previa de cancelar suma `plan_vencio`.

Cuotas y permanencia (ADR 0121): `/mi/productos` suma `inscripcion_minor`,
`permanencia_meses` y `condiciones`; `POST /mi/ordenes` y `POST /ordenes` aceptan
`acepta_condiciones` (sin él, un plan con permanencia responde 422 `TERMS_NOT_ACCEPTED`) y
caja, `exentar_inscripcion` + `motivo_exencion` (permiso `cuotas.exentar`). Las líneas de
orden traen `concepto` (null en un producto). `GET/PUT /cuotas`, `GET
/miembros/{persona}/cuotas`, `POST /ordenes/{orden}/lineas/{linea}/exentar` y `GET/POST
/acuerdos/{acuerdo}/baja` (simulación y baja: `efectiva_el`, `en_permanencia`,
`permanencia_hasta`, `modo`, `meses_restantes`, `precio_base_minor`, `porcentaje`,
`penalizacion_minor`, `adeudo_minor`; al dar de baja, `motivo`, `exentar_penalizacion`,
`conservar_adeudo`). Con `baja_anticipada = meses_restantes`, el plan acepta
`penalizacion_porcentaje` (1–100; vacío = 100), que se congela en cada membresía vendida
(ADR 0137).

Lugares numerados (ADR 0120): la vista previa trae `lugares` (`total`, `ocupados`; null si
la clase no los numera), `POST /mi/reservas` acepta `lugar`, `GET
/mi/sesiones/{sesion}/lugares` da `{total, ocupados, mio}` y `PUT
/mi/reservas/{reserva}/lugar` (`lugar`) elige o cambia el suyo; un lugar tomado o fuera del
catálogo responde 422 `SPOT_UNAVAILABLE`. Las reservas suman `lugar` y `lugares`.

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

## Timbres para facturar (pantalla propia)

- `GET /timbres` suma:
  - `resumen`: `usados_mes`, `usados_mes_anterior`, `promedio_mensual` (los 3 meses
    completos anteriores), `alcanza_meses` (`null` sin uso con qué estimar) y `por_mes`
    (12 meses en la zona del negocio: `mes`, `usados`, `comprados`);
  - `compra_pendiente`: la compra empezada y sin pagar (`id`, `cantidad`, `monto_minor`,
    `moneda`, `emitido_en`), que se paga con `POST /renta/cargos/{id}/pagar`.
- `GET /timbres/movimientos` filtra por `tipo` (`consumo`, `compra`, `ajuste`), `desde` y
  `hasta` (días del negocio) y `q` (cliente, RFC o folio fiscal de la factura), pagina
  con `page` y `per_page` (hasta 100) y exporta con `?formato=csv`. Cada fila trae:
  - `factura` (`id`, `cliente`, `rfc`, `total_minor`, `moneda`, `estado`, `uuid`) si es
    un consumo; quien está acotado a sus sedes recibe `cliente` y `rfc` en `null` y no
    puede buscar por cliente;
  - `compra` (`id`, `monto_minor`, `moneda`) si es una compra.
- `POST /timbres/comprar` acepta `retorno` (`/renta` o `/renta/timbres`): a dónde
  vuelve tras pagar.

## Listados que crecen con el uso (por páginas)

Lo que crece con cada visita de un cliente o con la operación se pide por páginas (`page`,
`per_page`) y responde `meta` (`total`, `page`, `per_page`, `ultima_pagina`). Sin `page`,
estos endpoints responden como antes (para versiones anteriores de la app):

- `GET /mi/planes` y `GET /miembros/{persona}/planes`: cada plan trae sus 10 usos más
  recientes (del más reciente al más antiguo) y `usos_total`. Los demás:
  `GET /mi/planes/{derecho}/usos` y `GET /miembros/{persona}/planes/{derecho}/usos`
  (hasta 50 por página). Las sumas del corte salen agrupadas del libro.
- `GET /derechos/{derecho}/movimientos` y `GET /mi/derechos/{derecho}/movimientos`: con
  `page`, por páginas (30 por omisión); sin ella, los últimos 200.
- `GET /miembros/{persona}/notas`: con `page`, 20 por página.
- `GET /miembros/{persona}/puntos`: con `page`, 20 por página (y `meta` junto a `data`);
  sin ella, los últimos 50.
- `GET /comunicaciones/difusiones`: con `page`, 20 por página; sin ella, las últimas 100.
- `GET /renta/quien-cuenta`: con `page`, quién cuenta en el cobro del mes por páginas
  (25 por omisión, hasta 100), con búsqueda por nombre (`q`, no cambia `cantidad`) y por
  qué cuenta cada quien: `reservas` y `compras` del mes de cada alumno, o `sesiones` de
  cada profesional. Sin ella, la lista completa como antes.
- `GET /renta/cargos`: el historial de cargos de la renta (renta, planes, ajustes y compras
  de timbres) por páginas, 12 por omisión. `GET /renta` sigue trayendo los últimos 36.
- `GET /mi/perfil` trae las reservas que aún no empiezan y, de sus planes, los que siguen
  en juego más los 3 vencidos más recientes.
- `GET /miembros/{persona}/ficha` trae los planes que siguen en juego y los 12 vencidos más
  recientes.
- `GET /plantillas-horario`: con `estado=vigentes`, solo las series que siguen generando
  fechas; con `estado=terminadas`, las que ya no se repiten (de la que terminó más
  reciente a la más vieja), por páginas con `page` (10 por omisión) y con los filtros de
  la semana (`actividad_id`, `instructor_id` o `sin-asignar`, `sucursal`).
- `GET /grupos/{grupo}/inscripciones`: con `page`, 20 por página, las activas primero,
  con búsqueda (`q`); cada inscripción trae el nombre completo y `persona_id`.

## Aviso de la renta en el panel (ADR 0152)

- `GET /renta/aviso` (con `facturacion.ver`): el pago de la suscripción por atender, o
  `null`. Trae `nivel` (`por_vencer`, `rechazado`, `vencido` o `por_suspender`),
  `cargo_id`, `periodo`, `concepto`, `monto_minor`, `moneda`, `vence_en`,
  `suspende_en` (`null` sin suspensión automática), `dias_para_suspension`,
  `rechazado`, `proximo_intento_en` y `con_tarjeta`.
- `por_vencer` sale a partir de `renta.dias_recordatorio_pago` días antes de vencer
  (parámetro de plataforma, 3 por omisión; 0 = solo ya vencido) y solo sin tarjeta
  domiciliada; `por_suspender`, dentro de `renta.dias_aviso_suspension` días antes de
  la suspensión.

## Nómina: devengado y tarifas con versiones (ADR 0153)

- `GET /nomina?desde&hasta`: el periodo son días del negocio. Cada fila trae
  `monto_total_minor` (devengado: solo sesiones que ya terminaron),
  `unidades_por_venir` y `por_venir_minor` (lo que falta del periodo; por asistente,
  con las reservas confirmadas), `al_cierre_minor` y `vigente_desde` de su tarifa. Si
  la tarifa de alguien cambió en el periodo, sale una fila por tarifa.
- `PUT /staff/{usuario}/esquema-pago` acepta `vigente_desde` (AAAA-MM-DD; por omisión,
  hoy) y responde desde cuándo vale; la primera tarifa de cada quien vale desde siempre.

## Nómina: cortes cerrados, pagados y ajustes (ADR 0157)

Todo con `estudio.gestionar` y la función `comisiones`, y en la bitácora (Equipo).

- `POST /nomina/cortes` (`desde`, `hasta`: AAAA-MM-DD) cierra el periodo con lo
  devengado por persona. 422 en `hasta` si no ha terminado (hasta ayer o antes) y en
  `desde` si se encima con otro corte.
- `GET /nomina/cortes` (`page`, 10 por página, lo más reciente primero): `estado`
  (`cerrado` | `pagado`), `total_minor`, `ajustes_minor`, `total_con_ajustes_minor`,
  quién lo cerró y el pago (`pagado_por`, `pagado_en`, `referencia_pago`).
- `GET /nomina/cortes/{corte}` además trae `lineas` (lo que quedó por persona y
  tarifa), `ajustes` y `diferencias`: por persona, lo del corte contra lo que da hoy el
  cálculo del mismo periodo.
- `POST /nomina/cortes/{corte}/pagar` (`referencia`) lo marca pagado (una vez: 422 si
  ya lo estaba).
- `DELETE /nomina/cortes/{corte}` lo reabre (vuelve a calcularse al vuelo). 422 en
  `corte` si ya está pagado.
- `POST /nomina/cortes/{corte}/ajustes` (`usuario_id`, `monto_minor` con signo: + a su
  favor, − en su contra; `motivo`). Solo para un corte pagado (antes, se reabre). No
  cambia el corte.

## Pon tu negocio en marcha: lo comprobado (adenda del ADR 0090)

- `GET /onboarding/quickstart` trae `comprobaciones`: `servicios`, `disponibilidad`,
  `precios`, `cobro`, `prueba` y `publicar`, en ese orden. Cada una lleva `hecho`
  (comprobado con los datos) y `ruta`, la pantalla donde se resuelve.
- `GET /pasarelas` trae `estado_conexion` en las de cobro en línea:
  - `sin_configurar`;
  - `credenciales`;
  - `activa_sin_comprobar`;
  - `comprobada`: hay un pago que aprobó ese proveedor, con `comprobada_en`.

## Importar citas futuras (ADR 0160)

Solo negocios de citas, con `agenda.gestionar` y `reservas.gestionar`.

- `GET /importaciones/citas/catalogos`: servicios, profesionales y sucursales (para las
  correspondencias) y `max_filas`.
- `GET /importaciones/citas/plantilla`: el CSV con sus columnas.
- `POST /importaciones/citas/preview` (multipart): `archivo`, `correspondencias` (JSON
  `{"servicio": {"nombre en el archivo": "<ulid>"}}`) y `recordatorios` (0/1).
  - Responde `ok`, `resumen` (`total`, `validas`, `invalidas`, `omitidas`,
    `clientes_nuevos`), `faltantes` por campo y `filas` (errores, avisos, `cliente` y
    `inicia_en`).
  - Con `ok`, trae también `confirmacion`.
- `POST /importaciones/citas`: lo mismo y `confirmacion`. Todo o nada; 201 con
  `creados`.

## Retención por segmentos (ADR 0159)

- `GET /retencion/segmentos` (`miembros.ver`; no para quien imparte): los grupos de su
  modalidad con `clave`, `etiqueta`, `total` y `con_promociones`:
  - `sin_asistir`;
  - `saldo_por_vencer`;
  - `vino_sin_comprar` (clases);
  - `pago_fallido`;
  - `debe_regresar` (citas).
- `GET /retencion/segmentos/{clave}` (`q`, `page`): `id`, `nombre`, `detalle` (el
  porqué), `recibe_promociones` y `seguimiento_abierto`. Una clave de otra modalidad
  responde 404.
- `POST /retencion/segmentos/{clave}/seguimientos` (`miembros.gestionar` y
  `tareas.gestionar`): una tarea por persona, sin repetir; responde `creadas` y
  `omitidas`.
- Las mismas claves son segmentos de `POST /comunicaciones/difusiones`.
  `GET /comunicaciones/segmentos` solo lista los de la modalidad del negocio.
- `PUT /ofertas/{oferta}` acepta `regreso_dias` (1 a 730, `null` lo quita): cada
  cuántos días suele regresar un cliente por ese servicio.

## Fallos R01–R06 (ADR 0164)

- **Grupos** (`/grupos…`): lista, contador y cada grupo por su ULID con el alcance de la
  sucursal de su plantilla (403 fuera de él); quien imparte, solo los suyos. Inscribir
  pide poder ver a la persona. Cada inscripción trae `alta_desde` y `baja_desde`; el
  contador `inscritos` incluye a quien tiene su baja después de hoy.
- **Cambio de grupo**: `GET /grupos/{grupo}/inscripciones/{inscripcion}/cambio?grupo_id=&desde=`
  es la vista previa exacta (`baja`, `alta`, `completo`, `aplicado: false`). `POST …/cambiar`
  es todo o nada; si es parcial responde 409 con esa misma vista en `meta` y no cambia
  nada, salvo con `parcial: true`.
- **Respuestas de una ficha** (`GET /formularios/{formulario}/respuestas`): solo las de
  quienes el usuario puede abrir el expediente (también en la búsqueda y el total).
- **Cobro de cita** (`POST /reservas/{reserva}/cobro`): siempre trae `venta_id` y
  `ticket` (aun solo el servicio) y `ticket_envio` (`enviado` | `fallido` | null). La
  misma `clave_intento` con lo mismo recupera el cobro; con otra cita, otros importes o
  en `POST /pos/ventas`, 409 sin cobrar nada.
- `POST /pos/ventas/{venta}/ticket` reenvía el ticket (solo el correo); 502 si vuelve a
  fallar. Quien vende en el mostrador o cobra citas, de su sede. `GET /pos/ventas` y los
  movimientos de caja no listan los comprobantes de solo servicio.

## Riesgos R07–R14 (ADR 0165)

- **Agenda** (`GET /sesiones`) e **Inicio** (`GET /inicio/hoy`): cada sesión trae
  `inicia_local` (y en la agenda `termina_local`, `AAAA-MM-DDTHH:MM:SS` sin zona) y, en
  la agenda, `utc_offset_min`: la hora de su sucursal para mostrarla y agrupar por día.
- **Nómina** (`GET /nomina/cortes/{corte}`): cada diferencia trae `en_corte_minor`,
  `ajustes_minor`, `reconocido_minor`, `hoy_minor`, `diferencia_minor` (lo que falta) y
  `diferencia_original_minor`.
- **Embudo** (`GET /reportes/embudo`): en un negocio de citas, `citas` con `agendadas`
  (`agendadas`, `confirmadas`), `asistencia` (`citas`, `asistieron`), `cobro`
  (`atendidas`, `cobradas`) y `siguiente` (`clientes`, `volvieron`); en clases, null.
- **Retención**: segmento `sin_iniciar` (también en `GET /comunicaciones/segmentos`). La
  última visita es la fecha de la sesión.
- **Pasarelas e integraciones**: `ultimo_exito_anterior_en` (lo que funcionó con llaves o
  modo anteriores); `comprobada` solo con evidencia posterior al último cambio.
- **Importar citas**: columnas opcionales `cliente_ref` y `pagado` (pesos). Sin correo,
  celular ni `cliente_ref`, la fila es un error; la misma cita con otros datos, también.
  `resumen` suma `por_cobrar`, `por_cobrar_minor`, `pagadas_antes` y `moneda`.

### Segunda revisión de R01–R14 (ADR 0166)

- **Grupos**:
  - Una inscripción con su baja aún por llegar sigue en el grupo: se puede adelantar
    la baja o cambiarla de grupo. Repetir la baja con la misma fecha o una posterior
    responde 409: solo se adelanta.
  - Cerrar un grupo adelanta al cierre las bajas que caían después.
  - Volver a inscribir a quien sigue inscrito conserva su `alta_desde`.
  - Cambiar de grupo exige que la persona sea del alcance de quien lo hace, también en
    `GET …/cambio`.
- **Fichas**: el expediente de alguien del equipo y sus respuestas piden `miembros.ver`
  además de `usuarios.gestionar`. En `GET /formularios`, `respuestas` cuenta solo las
  que quien pide puede leer.
- **Cobro de citas y mostrador**:
  - El mismo intento que llega dos veces a la vez devuelve el mismo cobro.
  - En el mostrador, repetir la clave y el cuerpo de una venta de otra sede responde
    403, no la venta.
  - En Tendencias, un comprobante de solo el servicio o la propina no cuenta como venta.
- **Ticket** (`ticket_envio`): suma `pendiente`, cuando ya se pidió y sigue en la cola.
  `enviado` solo cuando el correo salió; `fallido` cuando agotó sus intentos.
  `POST /pos/ventas/{venta}/ticket` tiene tope (10 por minuto).
- **Nómina** (`POST /nomina/cortes/{corte}/ajustes`):
  - acepta `compensa_diferencia` (bool, por omisión false); solo los que la compensan
    se restan de la diferencia;
  - cada diferencia suma `otros_ajustes_minor` (un bono, por ejemplo);
  - los ajustes anteriores cuentan como compensación.
- **Embudo de citas**: `citas.interes` {`solicitudes`, `con_cita`}, las solicitudes
  cuya persona agendó después una cita que sigue en pie. `confirmadas` ya no cuenta
  las canceladas ni las vencidas.
- **Inicio** (`GET /inicio/hoy`): cada sesión suma `utc_offset_min`; cada fila de
  `libres` suma `siguiente_local` (hora de la sede, sin zona).
- **Seguimiento automático, radar de renovaciones y embudo** (compra → asistencia):
  cuentan la fecha de la clase, no la del registro de la asistencia.
- **Pasarelas**: la comprueba un pago aprobado que se creó con la configuración
  vigente (un pago iniciado antes del cambio no cuenta). En el quickstart, «cobro» se
  cumple solo con una pasarela en línea comprobada (la de caja no cuenta). En Wellhub,
  la llave de avisos que genera el negocio renueva `configurada_en`.
- **Importar citas**:
  - Sin `referencia`, la misma persona y servicio ese día a otra hora es un error: si
    se movió, se cambia en la agenda; si son dos, llevan referencia.
  - Un celular de varias fichas (sin las archivadas), sin correo ni `cliente_ref`, es
    un error.
  - Lo pagado afuera queda en `ordenes.pagado_fuera_minor`, sin un pago.
  - Una cita pagada toda afuera tiene `estado_pago` `pagada`.

## Revisión operativa por áreas (ADR 0166)

- **Agenda:** `estado_atencion` suma `apartada` (cita apartada en línea que espera su
  pago). El pago de una cita cancelada (`estado_pago`) es null. Una cita cancelada
  conserva su titular en `cita` (su última reserva): de quién era y su historial.
- `POST /reservas/{reserva}/cancelar` suma `meta.efecto`, lo mismo que la vista previa
  (`GET /reservas/{reserva}/cancelacion`): qué pasó con el crédito, el pago o el
  anticipo.
- `GET /reservas/{reserva}/inasistencia` (`asistencia.marcar`): qué pasaría con su
  crédito si se marca que no vino. Devuelve `con_credito`, `penaliza`, `tolerada`,
  `pierde_credito` y `unidades`. La regla es la misma de `POST …/asistencia`.
- **Ficha** (`GET /miembros/{persona}/ficha`): cada derecho suma `todas_sucursales`,
  `sucursales`, `actividad` y `aplica_a` (qué deja reservar y dónde). El resumen
  (`GET /miembros/{persona}/resumen`) suma `sucursal` y `profesional` en
  `proxima_reserva`.
- **Portal** (`GET /mi/productos`): cada plan suma `ofertas`, `se_renueva` y
  `cobra_anualidad`.
- **Inicio** (`GET /inicio/hoy`): acepta `sucursal_id` (403 si no es suya). «Por cobrar»
  no cuenta las citas a las que el cliente no vino. «Seguimientos» se cuenta con el
  alcance de la lista de tareas. `totales.ocupados` y cada sesión `ocupados` siguen la
  regla del cupo (incluye Wellhub y TotalPass). `agenda.bloqueos[]`: `id`, `motivo`,
  `de`, `tipo` (`profesional`, `sala`, `sucursal`), `desde`, `hasta`, `todo_el_dia`,
  `zona_horaria`.
- **Sesión** (`POST /login`, `GET /yo`): `estudio.zona_desfase_min`, el desfase de hoy
  de la zona del negocio con UTC (minutos).
- **Embudo** (`GET /reportes/embudo`): `renovacion` cuenta las órdenes de renovación por
  la fecha en que vencía lo renovado (`ordenes.renueva_vence_el`), no por su creación.
- **Puesta en marcha** (`GET /onboarding/quickstart`):
  - «prueba» pide una reserva de un cliente (página pública o su cuenta);
  - las rutas de «cobro», «prueba» y «publicar» llevan a donde se hace.
- **Pagos** (`GET /pagos`): cada pago suma `por_conciliar` (incidencia abierta) y
  `comprobante` (`enviado`, `fallido`, `pendiente` o null).
  `PUT /pagos/{pago}/metodo` y `PUT /pos/ventas/{venta}/metodo` exigen `motivo`.
- **Nómina** (`PUT /staff/{usuario}/esquema-pago`): 422 en `vigente_desde` si cae en un
  periodo ya cerrado en un corte.
- **Fichas**:
  - `POST /formularios` acepta `borrador: true`; `POST /formularios/{formulario}/publicar`
    la publica (422 en `campos` si no tiene preguntas);
  - un borrador no se responde (422 en `formulario`);
  - `PUT /formularios/{formulario}` con respuestas y cambios de preguntas pide
    `confirmar_nueva_version: true` (si no, 409 `FORM_NEW_VERSION_UNCONFIRMED` con
    `meta.respuestas` y `meta.version`);
  - en `GET /formularios`, quien no diseña fichas ve solo las publicadas que le tocan, con
    `respuestas: null`.
- **Comunicaciones**:
  - `GET /comunicaciones/alcance?segmento=&canal=` (`comunicaciones.gestionar`): devuelve
    `elegibles`, `sin_consentimiento`, `sin_email` y `sin_app`;
  - cada difusión suma `fallidos` (agotaron sus intentos); `pendientes` ya no los cuenta.
- **Reportes**:
  - `GET /reportes/negocio` no cuenta las clases canceladas;
  - `GET /reportes/embudo` se acota a las sedes de quien lo pide.
- **Orden pagada después**: concede el plan como se vendió (`lineas_orden.plan`).

## Citas: cobrar al terminar (ADR 0158)

- `POST /reservas/{reserva}/cobro` (solo citas, `ordenes.gestionar`): `metodo`
  (`efectivo`, `tarjeta`, `transferencia`, `ventanilla`, `manual`), `items` (como en
  `POST /pos/ventas`; pide además `pos.vender` y la función `inventario`),
  `propina_minor`, `recibido_minor` (efectivo), `referencia`, `enviar_ticket` y
  `clave_intento`.
- Cobra el servicio si su orden sigue pendiente, más los productos y la propina, todo o
  nada. Responde `servicio_minor`, `productos_minor`, `propina_minor`, `total_minor`,
  `recibido_minor`, `cambio_minor`, `orden_id` y `ticket` (si hubo productos o
  propina; el servicio va primero).
- 422:
  - en `recibido_minor` si no alcanza;
  - en `reserva` si no hay nada que cobrar o la orden se canceló;
  - en `items.N.*` si un producto o extra ya no está a la venta.
- La misma clave devuelve el mismo cobro.

## Facturas: anticipos (ADR 0163)

- `POST /facturas` acepta `anticipo: true` (un solo concepto: el API fija la clave
  `84111506`, la unidad `ACT`, la cantidad 1 y «Anticipo del bien o servicio»; toma
  solo el importe) o `anticipos` (ULID de facturas de anticipo que aplica: mismo RFC,
  timbradas y sin aplicar). Con `anticipos` se relaciona con tipo `07` y cada concepto
  lleva «CFDI por remanente de un anticipo»; si alguno no cuadra, 422 en `anticipos`.
- Cada factura trae `anticipo` y `anticipo_aplicado`. `GET /facturas?anticipos_de=<RFC>`
  lista los anticipos timbrados y sin aplicar de ese RFC.

## Citas: anticipo para apartar (ADR 0161)

- `PUT /ofertas/{oferta}` (y el alta) aceptan `anticipo_minor` (monto, menor que el
  precio) o `anticipo_pct` (1 a 99), uno u otro; `null` lo quita. La oferta responde
  `anticipo_minor`, `anticipo_pct` y `anticipo_resultante_minor` (lo que resulta con su
  precio; `null` = se paga completo).
- `GET /citas/opciones` (y `/mi/citas/opciones`): cada servicio trae `anticipo_minor`
  (null sin anticipo o si el negocio no cobra en línea).
- `POST /citas` y `POST /mi/citas` aceptan `pagar` (`anticipo` por omisión, o `todo`).
  Con anticipo la cita queda `pendiente_pago`:
  - `POST /citas` responde `total_minor` (lo que se paga ahora) y `saldo_minor` (lo que
    queda para el negocio; `null` sin anticipo o pagando todo);
  - `GET /citas/orden/{orden}` también trae `saldo_minor`;
  - `POST /mi/citas` (y las reservas de Mi cuenta) traen en `cita` `anticipo_minor`
    (pagado) y, mientras falte el resto, `saldo_minor` y `saldo_orden_id`.
- Lo que agenda el negocio (`POST /agenda/citas`) no lleva anticipo.
- Agenda (`cita`): `total_minor` es lo que cuesta la cita; `anticipo_minor`, lo pagado
  al apartar; `por_cobrar_minor`, lo que falta. `POST /reservas/{reserva}/cobro` cobra
  solo el saldo.
- Vista previa de cancelar (`GET /mi/reservas/{reserva}/cancelacion`): `anticipo`
  (`devuelve` | `cobra` | `null`). Si el cliente cancela a tiempo, lo pagado en línea
  se devuelve solo; si cancela tarde, no.

## Servicios y clases: corregir y archivar (ADR 0154)

- `PUT /ofertas/{oferta}` acepta `nombre` y, solo en clases (grupales), `capacidad`
  (lugares por clase, 1 a 1000, `null` = sin cupo). El cupo nuevo vale para lo que se
  agende desde ahora; cada fecha ya agendada conserva el suyo. Archivada responde 409.
  Un nombre o un cupo corregidos quedan en la bitácora (`oferta.actualizada`).
- `POST /ofertas/{oferta}/archivar`: ya no se agenda, no se vende, no se incluye en un
  paquete ni aparece en línea; lo ya agendado sigue (`meta.sesiones_futuras`) y su
  historial también. 422 en `oferta` si un paquete vigente lo incluye o si una clase
  que se repite sigue generando fechas con él. `POST /ofertas/{oferta}/restaurar` lo
  vuelve a ofrecer. Los dos, con `catalogo.gestionar` y en la bitácora.
- `GET /ofertas` trae solo lo que se ofrece; con `archivados=1`, también lo archivado
  (`archivado: true`). Agendar, ver huecos o crear una serie con uno archivado da 422
  en `oferta_id`.

## Grupos: inscripción fecha por fecha, bajas, cambios y cierre

- `POST /grupos/{grupo}/inscripciones` responde `reservadas`, `en_espera`,
  `no_reservadas` y `sesiones` (cada fecha con `estado`: `reservada`, `en_espera` o
  `no_reservada`, y su `motivo`). Lo inesperado se registra en el log del negocio y
  no rompe la inscripción. Volver a inscribir a quien se dio de baja la reactiva. Un
  grupo cerrado responde 422 en `grupo`.
- `POST /grupos/{grupo}/inscripciones/{inscripcion}/baja` (`desde`: AAAA-MM-DD, hoy
  por omisión, no antes): cancela sus reservas del grupo desde ese día del negocio como
  cancelación del negocio (el crédito regresa, sin un aviso por fecha). `meta`:
  `canceladas` y `no_canceladas` (fecha y motivo). Ya dada de baja: 409.
- `POST /grupos/{grupo}/inscripciones/{inscripcion}/cambiar` (`grupo_id`, `desde`):
  baja en este y reservas en el otro desde la misma fecha; `meta.baja` y `meta.alta`.
- `POST /grupos/{grupo}/cerrar` (`desde`): ya no inscribe y da de baja a quienes
  seguían; `meta.bajas`, `canceladas`, `no_canceladas`. `POST /grupos/{grupo}/reabrir`
  lo vuelve a abrir (las bajas siguen).
- `GET /grupos` cuenta en `inscritos` solo a quienes siguen y trae `cerrado_en`; las
  inscripciones traen `baja_desde`. Todo queda en la bitácora (categoría alumnos).
- Las fechas que la agenda genera después (la ventana de cada noche, días nuevos de la
  serie) también reservan a los inscritos vigentes de los grupos abiertos, en el
  siguiente minuto, por el outbox (`agenda.sesiones_generadas`, interno: no sale por
  webhooks). El resumen queda en la bitácora (`grupo.fechas_nuevas`, ADR 0156).

## Espacios: corregir sin dejar fechas de más

- `PUT /recursos/{recurso}` acepta `nombre` (obligatorio si viene), `tipo`, `modo`
  (`unidad` o `pool`) y `capacidad` (solo en `pool`, 1 a 1000; en `unidad`, 422). Bajar
  la capacidad o volverlo exclusivo por debajo de las sesiones próximas que ya lo usan
  a la vez da 422 en `capacidad` (o `modo`) con la fecha y cuántas son. La corrección
  queda en la bitácora (`recurso.actualizado`).

## Privacidad: búsqueda, estado y vencimiento

- `GET /solicitudes-privacidad?page=…` acepta `q` (nombre), `estado` (`pendiente`,
  `atendida`, `rechazada`) y `vencimiento` (`vencidas` o `por_vencer`: pendientes con 5
  días hábiles o menos). `meta` suma `vencidas` y `por_vencer`. `vence_en` (20 días
  hábiles) se cuenta desde el día del negocio en que se pidió.

## Embudo: solo lo comparable con las visitas

- `POST /captacion/formularios/{formulario}/envios` acepta `canal` (`pagina`, `widget`,
  `enlace` o `embebido`); el prospecto guarda el de su primera solicitud.
- `GET /reportes/embudo`: `sitio_a_solicitud.solicitudes` cuenta solo las que llegaron
  por la página o el widget (pasan por una visita medida). Las demás van en
  `sitio_a_solicitud.otras` (`enlace`, `embebido`, `sin_canal`, `equipo`), sin
  compararse con las visitas. La web no muestra porcentaje si la cantidad pasa a la
  base.

## Bitácora en la zona del negocio

- `GET /auditorias` rechaza (422 en `hasta`) un rango con el final antes del inicio; las
  fechas son días del negocio. La web muestra las horas en la zona del negocio y dice
  cuál es.

## Presupuestos: se envía y se acepta la versión revisada

- Cada presupuesto trae `revision`, que sube con cada guardado. `POST
  /presupuestos/{id}/enviar` y `/aceptar` aceptan `revision`: si ya no es la guardada
  (alguien lo cambió después), responden 409 con `meta.motivo: cambio` y no envían ni
  aceptan nada. Guardar y aceptar bloquean la fila.

## Espacios: el único espacio de un servicio

- Eliminar un espacio inactivo que es el único de un servicio da 422 en `recurso`: sin
  él, el servicio dejaría de pedir espacio y se agendaría sin uno.

## Revisión para producción (ADR 0151)

- `GET /sesiones` trae hasta 1500 sesiones; si el rango tiene más, `meta.truncado: true`
  (conviene pedir un rango menor). Acepta `oferta_id` para ver solo una clase o servicio.
- `POST /mi/formularios/{formulario}/respuestas` (`valores`): el cliente llena una ficha
  de datos desde su cuenta, la suya o, con `X-Participante`, la de una persona a su cargo.
  Responde igual que `POST /formularios/{formulario}/respuestas`.
- `POST /mi/citas` y la cita pública ignoran `duracion_minutos`: vale la del servicio o
  `citas.duracion_defecto`. La cita pública pide `recaptcha_token` cuando el captcha está
  configurado.
- Lo que cuelga de un cliente (expediente, documentos, reservas, créditos, membresías,
  órdenes, cuotas, incidencias, facturas) responde 404 a quien está acotado a otras
  sedes. Tareas, mensajes, reseñas y canjes se listan solo de sus sedes.
- `POST /llaves-api` da 422 en `scopes` con un permiso que quien la crea no tiene y 403 a
  quien está acotado a sus sedes.
- `PUT /avisos-plataforma`, `POST /timbres/comprar` y la factura de la renta piden
  `estudio.gestionar`.

## Listados por páginas: órdenes, mensajes y entradas

Ya no tienen un tope de 200. Van por páginas y con `meta` (`total`, `page`, `per_page`,
`ultima_pagina`). Las fechas son días del negocio; con `hasta` antes que `desde`, 422.

- `GET /ordenes?q&estado&desde&hasta&page&per_page` (25 por omisión, hasta 100):
  - `q` busca al comprador; `estado` es `pendiente`, `pagada` o `cancelada`; las fechas,
    de creación;
  - `meta.resumen` es lo de hoy, sin los filtros: `ventas_hoy`, `vendido_hoy` (por
    moneda) y `por_cobrar`.
- `GET /mensajes?q&estado&canal&desde&hasta&page&per_page` (25 por omisión):
  - `q` busca en el asunto, el destinatario o la persona;
  - `meta.conteos` cuenta lo encontrado por estado, sin el filtro de estado;
  - cada mensaje suma `creado_en`.
- `GET /accesos?q&resultado&desde&hasta&page&per_page` (50 por omisión):
  - `q` busca a la persona; `resultado` es `permitido` o `denegado`;
  - quien está acotado a sus sucursales ve sus entradas y las registradas sin sucursal;
  - `meta.conteos` cuenta lo encontrado por resultado, sin ese filtro.

## Descargar los movimientos

- `GET /pagos?formato=csv` descarga lo que encuentra la lista de Movimientos con los
  mismos filtros (`q`, `estado`, `desde`, `hasta`), hasta 5,000, con las fechas en la zona
  del negocio (`movimientos.csv`, con BOM). Columnas: Fecha, Persona, Monto, Moneda,
  Devuelto, Estado, Forma de pago y Registró.

## Asignar y editar tareas

- `POST /tareas` suma `persona_id` (ULID de una persona del negocio) y `responsable_id`
  (ULID de alguien del equipo). Sin `responsable_id`, la tarea es de quien la crea.
  Asignarla a otro pide `tareas.equipo` y que esa persona atienda tareas
  (`tareas.gestionar`); si no, 422.
- `PUT /tareas/{tarea}` (`tareas.gestionar`): edita una tarea pendiente (`titulo`,
  `detalle`, `vence_en`, `persona_id`, `responsable_id`). Lo que no viene no cambia. Una
  completada no se edita (422).
- `GET /tareas/responsables` (`tareas.equipo`): quienes pueden recibir una tarea
  (`id`, `nombre`).
- `GET /tareas?responsable={ulid}` (con `tareas.equipo`): las de alguien del equipo.
  Cada tarea suma `responsable_id`.

## Campañas controladas (B08)

- Las difusiones traen `estado` (enviando, pausada, cancelada), `enviados`, `pendientes`
  y `descartados`. `POST /comunicaciones/difusiones/{difusion}/pausar|reanudar|cancelar`
  (`comunicaciones.gestionar`). La misma campaña (segmento, canal, asunto y cuerpo) en
  menos de 24 horas: 422.
- Al enviar, un mensaje de campaña a quien no recibe promociones se descarta.
- `GET /promociones/baja/{persona}` (enlace firmado y con vencimiento, en los correos de
  campaña): deja de recibir promociones.

## Check-ins de Wellhub y TotalPass (M10)

- `POST /checkins` contesta 201 si el proveedor aprobó la visita y 422 `CHECKIN_INVALID`
  si la rechazó (no se guarda). Contesta 409 `CHECKIN_UNVERIFIABLE` si no la pudo
  verificar: sin conexión, error de su lado (5xx, 429) o credenciales que no aceptó. En
  ese caso la visita queda `no_verificable` (`meta.checkin`), nunca aprobada. Reintentar
  con el mismo código en la misma clase la retoma. La visita del día ya registrada en otra
  clase: 422 con la hora de esa clase.
- `GET /sesiones/{sesion}/checkins`: `estado` (`validado`, `no_verificable`,
  `excepcion`), `motivo`, `registrado_por`, `autorizado_por`, `autorizado_en`,
  `motivo_excepcion` y `evidencia`.
- `POST /checkins/{checkin}/excepcion {motivo, evidencia}` (`checkins.excepcion`): solo
  sobre una visita sin verificar. Queda en la bitácora (`checkin.excepcion`).
- `PUT /integraciones/{proveedor}` registra en la bitácora `integracion.actualizada` con
  los nombres de las llaves cambiadas, nunca sus valores. Alcance: validación de
  check-in; no sincroniza reservas, membresías ni pagos (ADR 0124).

### Visitantes y check-in automático (ADR 0148)

- Quien llega por Wellhub o TotalPass es visitante de la plataforma, no persona del
  negocio. Su visita ocupa lugar en la clase: cuenta en `ocupados` y `libres` de la agenda
  y en el uso del canal de su plataforma.
- `GET /sesiones/{sesion}/checkins` suma `visitante_id` y `origen` (`manual` o
  `automatico`).
- `GET` y `PUT /integraciones/{proveedor}` suman `automatico`: `aviso_url` (la dirección
  de avisos del negocio), `listo` y `registrado_en`. Las credenciales suman
  `webhook_secret` (Wellhub) y `place_api_key` (TotalPass). Con `place_api_key`,
  AgendaUno registra su aviso con TotalPass al guardar.
- `POST /api/v1/webhooks/bienestar/{estudio}/{proveedor}/{token}` (público, para la
  plataforma):
  - 404 sin el token del negocio o con la integración apagada;
  - Wellhub: 401 con una firma `X-Gympass-Signature` que no es la suya;
  - 200 al recibir el aviso (se procesa aparte). Un aviso repetido no duplica la visita.
- `GET /reportes/plataformas?desde&hasta[&proveedor]` (`facturacion.ver`, solo clases):
  - `resumen`: `visitas`, `validadas`, `sin_verificar`, `excepciones`, `automaticas`,
    `sin_clase`, `visitantes`, `nuevos`;
  - `por_proveedor`, `por_dia` y `por_clase` (las 10 con más visitas).
- `GET /visitantes-plataforma?q&proveedor&page` (`facturacion.ver`):
  - campos: `id`, `proveedor`, `nombre`, `email`, `telefono`, `producto`, `id_externo`,
    `visitas`, `primera_visita_en`, `ultima_visita_en` y `es_alumno` (el negocio ya
    registró a alguien con ese correo);
  - `meta` con la página.
- `GET /visitantes-plataforma/{id}`: lo mismo y `historial` (fecha, clase, estado y
  origen de sus visitas).

### Reservas desde la app de la plataforma (ADR 0149)

- `GET /integraciones/{proveedor}/reservas` (`integraciones.configurar`, solo clases):
  - `disponible`: si se publican clases en esa plataforma (TotalPass, solo con la llave
    de integrador de AgendaUno);
  - `conectada`, `activas`, `sucursal_id` y `sucursales`;
  - `dias_publicados` y `lugares_sin_tope` (parámetros del negocio);
  - `avisos`: `registrados`, `registrado_en` y `aviso_url`;
  - `productos`: los productos de Wellhub o los planes de TotalPass (`id`, `nombre`,
    `virtual`), consultados en el momento; `null` si no respondió;
  - `clases`: las ofertas grupales con `oferta_id`, `nombre`, `publicada`, `producto` y
    `error` (lo que la plataforma no aceptó);
  - `horarios`: `publicados` y `con_error` de las sesiones por venir, `ultimo_error` y
    `sincronizado_en`;
  - `reservas_por_venir`.
- `PUT /integraciones/{proveedor}/reservas`:
  - cuerpo: `activas`, `sucursal_id` (obligatoria al encender) y
    `clases: [{oferta_id, publicada, producto}]`;
  - 422 sin la integración conectada y activa, si una clase publicada no trae producto o
    si la plataforma aún no publica clases;
  - responde lo mismo con `productos: null` y `error_avisos` (si no se pudo registrar la
    dirección de avisos con la plataforma);
  - publica después de responder; `agendauno:sincronizar-plataformas` sigue cada 5
    minutos.
- El mismo `POST /api/v1/webhooks/bienestar/{estudio}/wellhub/{token}` recibe
  `booking-requested`, `booking-canceled`, `booking-late-canceled` y
  `checkin-booking-occurred`. La reserva se acepta o rechaza con la Booking API de
  Wellhub; un aviso repetido no vuelve a contestar.
- `POST /api/v1/webhooks/bienestar/{estudio}/totalpass/{token}` recibe además los avisos
  de reserva de TotalPass (`event`, `user`, `slot` con su `status`). Se comprueban con
  TotalPass antes de decidir y se confirman o rechazan con su `confirmSlot`.
- `GET /reportes/plataformas` suma `reservas` (`total`, `por_venir`, `asistieron`,
  `canceladas`, `canceladas_tarde`, `no_asistieron`, `rechazadas`, por la fecha de la
  clase) y `reservas` en cada `por_proveedor`.
- `GET /visitantes-plataforma/{id}` suma `reservas` (clase, inicio y estado).

## Migración guiada (C07)

- Las vistas previas e importaciones de `miembros` e `instructores` devuelven `estado` por
  fila (`valida`, `invalida`, `existente`, `conflicto`) y en el resumen `existentes`,
  `conflictos` y `rechazadas`. `parcial=1` carga solo las válidas. Lo que ya estaba no se
  repite.
- Miembros acepta además `celular`, `fecha_nacimiento` (AAAA-MM-DD) y `genero`.
- `POST /importaciones/instructores` exige `invitar` (`1` envía las invitaciones, `0`
  deja las cuentas sin invitar) y devuelve `invitados`.
- `POST /importaciones/saldos/preview` y `POST /importaciones/saldos`
  (`membresias.gestionar` y `creditos.gestionar`): `corte`, `evidencia`, `parcial` y un
  CSV con `email`, `plan`, `creditos`, `vence` e `inicio`. Crean el plan con `origen =
  apertura` y una concesión de créditos con origen `apertura`, sin orden ni pago, y
  devuelven `cargados`. Cada carga queda en la bitácora con su resumen y duración
  (ADR 0125).

## Reporte por campaña (ADR 0144)

- `GET /reportes/campanas?desde=&hasta=` (`facturacion.ver`, `reportes_avanzados`;
  últimos 30 días por omisión, hasta un año): `{desde, hasta, moneda, filas}`.
- Cada fila trae `campana` (`{campana, fuente, medio, referente}` o `null` = sin
  atribución) y sus cifras:
  - `solicitudes`;
  - `nuevos_compradores`;
  - `compras` y `compras_minor`;
  - `devoluciones` y `devoluciones_minor`;
  - `primeras_reservas`;
  - `renovaciones` y `renovaciones_minor`, aparte de la adquisición.
- `GET /plataforma/embudo` agrega `campanas`: por producto y campaña de registro,
  `registros`, `primera_operacion` y `primera_suscripcion`, sin demos.

## GA4 y Meta de cada negocio (ADR 0143)

- Públicos:
  - `GET /medicion`: `{ga4, meta: null}`. Es el ID de su GA4 si está encendido, no es
    demo y su plan tiene `integraciones`. El píxel de Meta no se carga en un negocio;
  - `POST /medicion/consentimiento`: `{visitante, analitica, publicidad}`. 204.
- Con `integraciones.configurar`:
  - `GET /medicion/destinos`: sus destinos y los últimos 50 envíos;
  - `PUT /medicion/destinos/{ga4|meta}` (además, `plan:integraciones`): lo mismo que
    en el superadmin, más `eventos_extra` (`asistencia`, `renovacion`). Queda en la
    auditoría sin el secreto;
  - `DELETE /medicion/destinos/{proveedor}`;
  - `POST /medicion/destinos/{proveedor}/probar`.
- Aceptan `medicion` (como en `POST /registro`):
  - `POST /captacion/formularios/{f}/envios`;
  - `POST /citas`;
  - `POST /mi/ordenes`.

## GA4 y Meta del SaaS (ADR 0143)

- `GET /medicion/{agendauno|turnouno}` (público): `{ga4, meta}`, los IDs de los destinos
  encendidos de ese producto (nunca sus secretos).
- `POST /medicion/consentimiento` (público): `{visitante, analitica, publicidad}`. Se
  aplica a lo que ese visitante ya registró: borra lo retirado. 204.
- `POST /registro` acepta `medicion`: `{visitante, analitica, publicidad, ga_client_id,
  ga_session_id, fbp, fbc, url}`.
  - Solo se guarda lo autorizado: GA4 con analítica; `fbp`, `fbc`, agente e IP con
    publicidad.
  - La respuesta trae `medicion.eventos`: los `event_id` de `negocio_creado` y
    `prueba_iniciada`.
- Superadmin:
  - `GET /plataforma/medicion`: los destinos por producto y proveedor, y los últimos 50
    envíos;
  - `PUT /plataforma/medicion/{producto}/{ga4|meta}` con `identificador`, `secreto`
    (vacío lo conserva), `codigo_prueba` (Meta), `activo` y `aviso_confirmado`;
  - `DELETE` desconecta: borra ID y secreto, y lo pendiente queda omitido;
  - `POST …/probar` responde `{ok, mensajes}`.
- `agendauno:enviar-conversiones` (cada minuto): detecta, manda, reintenta y vence
  identificadores.

## Atribución de campañas (ADR 0142)

- `atribucion` (opcional) en `POST /registro`, `POST /captacion/formularios/{f}/envios`,
  `POST /citas` y `POST /mi/ordenes`:
  `{primer_toque, ultimo_toque}`, cada uno con `fuente`, `medio`, `campana`,
  `contenido`, `termino`, `referente` (host) y `en` (ISO 8601).
- El servidor valida de nuevo y descarta lo que no sirve sin rechazar la solicitud.
  Aplica `medicion.dias_ultimo_toque` y `medicion.dias_primer_toque` (parámetros del
  negocio; en el registro, los de la plataforma).
- Se acredita la campaña del último toque vigente. Sin él, sin atribución.
- `GET /prospectos/{p}` agrega `campana`: `{fuente, medio, campana, referente,
  primer_origen}` o `null` (sin atribución).
- `GET /mi/interes?plan={ulid}` o `?clase={ulid}`: lo que eligió en la página antes de
  entrar, si es de este negocio y sigue público. Sin esos parámetros, lo del prospecto
  (ADR 0137).

## Widgets para la web del negocio (ADR 0141)

- `GET/POST /captacion/widgets` y `PUT/DELETE /captacion/widgets/{widget}`
  (`prospectos.ver` y `prospectos.gestionar`). Campos:
  - `tipo`: `calendario` (solo clases) o `planes`;
  - `nombre`;
  - `filtros` (`sucursal` y `actividad`, ulids);
  - `tema`: `claro`, `oscuro` o `auto`;
  - `acento`: `#rrggbb`;
  - `dominios`: se guardan como dominio, sin esquema ni ruta;
  - `activo`.
- `GET /widgets/{widget}` (público): su configuración, `negocio` y `modalidad`. Apagado
  o con la página del negocio cerrada, 404.
- La web los muestra en `/w/{negocio}/{widget}` con el calendario público
  (`GET /calendario`), el escaparate y el formulario de captación.

## Complementos que se piden (ADR 0140)

- `GET /renta/complementos` (`facturacion.ver`): los módulos que se activan a pedido,
  cada uno con `clave`, `nombre`, `descripcion`, `activo` y `solicitado_en`.
- `POST /renta/complementos/{modulo}/solicitud` (`estudio.gestionar`): registra la
  alerta `modulo_solicitado` para la plataforma.
  - Ya activo: 422.
  - Módulo que no existe: 404.
- Se activa con `PUT /plataforma/estudios/{estudio}/modulos` (superadmin).

## Mostrador: extras, propina y cambio (ADR 0139)

- Extras de un artículo:
  - `POST/PUT /articulos` aceptan `extras` (`[{id?, nombre, precio_minor}]`): los que
    traen `id` se actualizan, los nuevos se crean y los que faltan se apagan;
  - el artículo trae sus `extras` vigentes.
- `POST /pos/ventas`:
  - acepta `items.*.extras` (ulids de extras del artículo), `propina_minor` y
    `recibido_minor` (en efectivo);
  - lo recibido debe alcanzar para lo vendido y la propina (si no, 422
    `recibido_minor`).
- La venta trae `propina_minor`, `cobrado_minor`, `recibido_minor`, `cambio_minor` y,
  por línea, `extras` (nombres).
- La propina ya no lleva «para quién» (ADR 0145).

## Mostrador: a quién se le vende (ADR 0145)

- `GET /pos/compradores?q=` (`pos.vender`, al menos 2 letras): hasta 8 personas del
  negocio con `id` (`persona:<ulid>` o, si es del equipo sin ficha, `usuario:<ulid>`),
  `nombre`, `tipo` (`miembro`, `instructor`, `staff`), `genero`, `email` y `celular`.
  - Quien vende acotado a sucursales solo encuentra a los alumnos de las suyas.
- `POST /pos/ventas` acepta además:
  - `comprador_id`: el `id` de arriba; sin él, venta a público en general. Uno que no
    es del negocio (o de otra sede, para un acotado) da 422 `comprador_id`;
  - `enviar_ticket`: manda el ticket por correo a la persona. A público en general se
    ignora; a una persona sin correo, 422 `enviar_ticket`.
- La venta trae `folio`, `comprador` (`{id, nombre, tipo, genero}` o `null`) y
  `ticket_enviado_en`.
- `GET /pos/ventas`:
  - por páginas: `page`, `per_page` (hasta 100; 20 por omisión);
  - filtros: `desde` y `hasta` (días del negocio), `sucursal_id` y `persona_id`.
    `hasta` antes que `desde` da 422;
  - `meta`: `total`, `page`, `per_page`, `ultima_pagina`, `resumen` (lo filtrado) y
    `hoy` (hoy en esas sucursales). Los dos traen `{ventas, vendido_minor,
    propinas_minor}` sin anuladas.
- `GET /mi/compras-mostrador` (cualquier sesión, por páginas): los tickets de quien
  tiene la sesión o de la persona a su cargo (`X-Participante`). Cada uno con `folio`,
  `creado_en`, `sucursal`, `lineas` (`articulo`, `cantidad`, `extras`,
  `subtotal_minor`), `total_minor`, `propina_minor`, `cobrado_minor`, `recibido_minor`,
  `cambio_minor`, `moneda`, `metodo_pago` y `anulada`.
- `GET /pagos/movimientos`: las ventas traen `propina_minor`, y los totales
  `propinas_minor` y `propinas_por_metodo`, aparte de `cobrado_minor`.

## Quién atiende a un prospecto (ADR 0138)

- `GET /prospectos/responsables` (`prospectos.ver`): el equipo activo que puede
  gestionar prospectos (`id`, `nombre`, `email`), para elegir responsable.
- `PUT /prospectos/{p}/responsable` deja en la historia de quién a quién
  (`detalle.responsable`, `detalle.anterior`).
- `POST /prospectos/{p}/contactos` (`prospectos.gestionar`): `canal` = `whatsapp`,
  `llamada` o `correo`. Registra el evento `contacto` y cuenta como primer contacto.
- Sin atender:
  - los prospectos traen `sin_atender_desde`;
  - `GET /prospectos?atencion=sin_atender` los filtra y `meta.resumen.sin_atender` los
    cuenta;
  - la historia suma los eventos `contacto` y `sin_atender` (`detalle.responsable`,
    `detalle.horas`);
  - el aviso al equipo es el evento `prospecto.sin_atender`;
  - el plazo es el parámetro `captacion.horas_para_atender` (0 = no se revisa).

## Permanencia, clase de prueba y continuidad del prospecto (ADR 0137)

- Planes: `penalizacion_porcentaje` (arriba, en «Cuotas y permanencia»).
- Clase de prueba: una orden con dos líneas de clase de prueba para la misma persona
  responde 422 `FIRST_VISIT_ONLY`. Si una orden pagada trae una que ya no se puede
  conceder (otra orden pagada a la vez ya se la dio), esa línea no se concede y el pago
  queda en `GET /incidencias-cobro` con `tipo = primera_vez_repetida`.
- `GET /mi/interes`: lo que pidió cuando era prospecto. Devuelve `data: {plan, clase}`
  o `data: null`:
  - `plan`: `id`, `nombre`, `precio_minor`, `moneda`;
  - `clase`: `id`, `nombre`, `inicia_en`, `zona_horaria`.

  Cada uno solo si sigue disponible y aún no lo tiene, y durante 30 días después de
  convertirse. No aparta lugar.

## Pago automático seguro (ADR 0134)

- Quitar una suscripción (Mercado Pago, OpenPay) la deja en estado `cancelando` hasta
  que la pasarela confirma.
  - `agendauno:confirmar-cancelaciones` la reintenta cada 5 minutos.
  - Tras 5 intentos sin confirmar queda en «Por conciliar».
- `GET /mi/pago-automatico`, en cada membresía:
  - `cancelando`;
  - `monto_minor`: con pago automático, el precio que autorizó;
  - `precio_nuevo_minor`: el del plan, si cambió.
- `POST /mi/pago-automatico/{acuerdo}/nuevo-precio` autoriza el precio nuevo:
  - responde `activa`, `redirect` (otra suscripción que se autoriza en la pasarela) o
    `formulario` (OpenPay);
  - al quedar activa, la anterior se cancela.
- `GET /suscripciones`:
  - `cancelando_pago_automatico`;
  - `pago_automatico.monto_minor` y `pago_automatico.precio_nuevo_minor`.
- `GET /incidencias-cobro`:
  - tipos nuevos: `cancelacion_sin_confirmar`, `cobro_tras_cancelar` y `monto_distinto`;
  - campos `domiciliacion {proveedor, referencia, estado}`, `esperado_minor`,
    `excedente_minor` y `aplicable`.
- `POST /incidencias-cobro/{id}/resolver`:
  - `accion: aplicar` («dar por pagada» la renovación con ese cobro);
  - `accion: devolver`: con excedente, devuelve solo el excedente.

## Equipo y bitácora del superadmin (ADR 0132)

- Toda petición del superadmin que cambia algo queda en `bitacora_plataforma`
  (operador, acción, negocio, datos sin secretos, IP, resultado).
  `GET /plataforma/bitacora?operador=&estudio=&accion=&pagina=`.
- Operadores: `GET`/`POST /plataforma/administradores` y `PUT
  /plataforma/administradores/{id}` (`nombre`, `activo`, `password` ≥ 12). Siempre queda
  uno activo; nadie se desactiva a sí mismo.
- Sesiones: `GET /plataforma/sesiones` (con `actual`) y `DELETE /plataforma/sesiones/{id}`.
- `GET /plataforma/estudios`: `adeudos[]` por moneda (`{moneda, minor}`). La ficha trae
  `ultima_suspension`.
- `PUT /plataforma/pasarelas/stripe`: si las llaves cambian de modo, 409
  `GATEWAY_MODE_CHANGE_UNCONFIRMED` hasta mandar `confirmar_cambio_modo: true`.

## Cada producto habla como sus negocios (ADR 0131)

- `GET /terminologia` trae en `opciones` solo las de la modalidad del negocio; otra
  responde 422.
- `GET /parametros` lista solo los de su modalidad (cada definición trae `modalidad`:
  `clases`, `citas` o null); guardar uno de la otra modalidad responde 422.
- `GET /roles` trae en `catalogo` solo los permisos de su modalidad.
- `GET /plataforma/whatsapp`: las plantillas de los negocios traen `modalidad` (las de
  citas son `turnouno_*`). Los avisos al dueño llevan `{{marca}}`.

## TurnoUno sin membresías (ADR 0130)

- En un negocio de citas, crear, editar, vender u ofrecer algo que funcione como
  membresía responde 422 `MEMBERSHIP_NOT_AVAILABLE`: tipo `membresia`, que se renueve
  (`politica_reset`), ilimitado, con permanencia o con inscripción o anualidad. Se
  vende el bono de sesiones (`tipo: paquete`).
- `/mi/productos`, el escaparate y el catálogo de presupuestos no listan membresías
  en citas.
- Pago automático en citas: 422 `DIRECT_DEBIT_NOT_ALLOWED`; `/mi/perfil` trae
  `pago_automatico: false`.
- `/cuotas`, `/miembros/{persona}/cuotas` y exentar una cuota son `modalidad:clases`.

## Presupuestos (negocios de citas, ADR 0127)

- `GET /presupuestos?estado=&q=` (`presupuestos.ver`; `estado` admite `vencido`) y
  `GET /presupuestos/{id}`.
- `POST /presupuestos` y `PUT /presupuestos/{id}` (`presupuestos.gestionar`):
  - el cliente va como `persona_id`, o como `nombre_contacto`, `email_contacto` y
    `celular_contacto`;
  - también admiten `sucursal_id`, `valido_hasta`, `descuento_minor` y `notas`;
  - cada elemento de `lineas` lleva `tipo` (`servicio|producto|articulo|libre`),
    `referencia`, `descripcion`, `cantidad`, `precio_unitario_minor` y
    `descuento_minor`.
- `DELETE /presupuestos/{id}` borra solo borradores.
- `POST /presupuestos/{id}/enviar|aceptar|rechazar` (`rechazar` admite `motivo`).
- `POST /presupuestos/{id}/convertir` (además `ordenes.gestionar`) devuelve la orden de
  cobro.
- `GET /presupuestos/catalogo` y `GET|PUT /presupuestos/configuracion` (`impuestos`,
  `tasa_impuesto_bp`, `vigencia_dias`, `notas`).
- Errores: `QUOTE_NOT_ALLOWED` con `meta.motivo` (`respondido`, `vencido`,
  `no_aceptado`, `no_borrador`, `sin_correo`, `sin_cliente`, `sin_conceptos`…).

## Página de cada negocio para buscadores (ADR 0128)

Las pide nginx en el subdominio del negocio, no la web. El negocio sale del host y no se
abre su base de datos.
- `GET /pagina-negocio/cabeza` con `X-Pagina: {dirección}`: las etiquetas del `<head>`
  de esa página (título, descripción, Open Graph, canonical y `LocalBusiness`). Responde
  204 si no es una página pública del negocio.
- `GET /pagina-negocio/sitemap.xml`: 404 si el negocio no se indexa.
- `GET /pagina-negocio/robots.txt`.
- El escaparate y `GET /citas/opciones` traen `estudio.en_directorio`: «solo con enlace»
  no se indexa.

## Respuestas privadas (M11)

- Toda respuesta de la API a una petición con credenciales (`Authorization` o
  `X-API-Key`) lleva `Cache-Control: no-store, private`, salvo la que declara su propia
  caché (los calendarios `.ics`, con `max-age`). Todas llevan `X-Robots-Tag: noindex`.

## Embudo de la plataforma (C02, C08)

- `POST /metricas/visita-landing` (público, `text/plain` con `{"producto"}`): una visita
  anónima por visitante y día a la landing de un producto; 204 siempre.
- `GET /plataforma/embudo?desde&hasta` (superadmin): por producto, `visitas_landing`,
  `registros` (negocios creados en el periodo, sin demos), `verificados`, `publicados`,
  `primera_operacion`, `primer_pago`, `dias_a_publicar` y `dias_a_operar` (medianas).
- `estudios.publicado_en` se anota al publicar por primera vez; `primera_operacion_en`,
  con `agendauno:registrar-hitos` (diario); `es_demo` lo pone la siembra de demos.

## Cobros comprensibles (M07)

- `GET /pasarelas`: cada pasarela en línea suma `confirma_pagos` (tiene las llaves de sus
  avisos: `webhook_secret`, o `webhook_user` y `webhook_password` en OpenPay) y
  `en_prueba`. En producción, `lista` exige `confirma_pagos`.
- `PUT /pasarelas/{proveedor}`: 422 en `modo` si las llaves delatan el otro modo (Stripe
  `sk_test_`/`sk_live_`; Mercado Pago `TEST-`).
- `/mi/perfil` suma `pago_en_linea_prueba`; las órdenes pendientes de `/mi/*` traen
  `pago_en_proceso` {`metodo`, `iniciado_en`} si hay un intento en línea sin confirmar.
- Una devolución deja `pago.reembolso_solicitado` en la bitácora.

## Acciones del equipo desde el inicio y la sesión (M08)

- `GET /inicio/hoy` suma `atencion` {`solicitudes` (prospectos nuevos, con
  `prospectos.ver`), `seguimientos` (tareas de seguimiento abiertas, con `tareas.ver`)};
  null en lo que no le toca ver.
- `GET /sesiones/{sesion}/reservas`: cada reserva trae `orden` {`id`, `total_minor`,
  `moneda`, `concepto`} si falta pagarla, para cobrarla desde la sesión.

## Seguimiento y embudo (ADR 0122)

- `GET /tareas?tipo=seguimiento`: solo las de seguimiento. Cada tarea suma `persona_id`
  (ulid), `celular`, `motivo` (`primera_asistencia`, `plan_por_vencer`, `plan_vencido`,
  `sin_asistir`, `compro_sin_reservar`), `resultado` y `contactado_en`.
- `POST /tareas/{tarea}/resultado` (`tareas.gestionar`): `resultado` (`contactado`,
  `renovo`, `no_respondio`, `no_desea_contacto`, `requiere_atencion`) y `nota` opcional
  (hasta 500). Completa la tarea; `no_desea_contacto` apaga `recibe_promociones`.
- `GET /reportes/embudo?desde&hasta` (`facturacion.ver`, plan `reportes_avanzados`; por
  omisión los últimos 30 días, hasta un año): `sitio_a_solicitud` {`visitas`,
  `solicitudes`}, `solicitud_a_compra` {`solicitudes`, `compraron`},
  `compra_a_asistencia` {`compradores_nuevos`, `asistieron`}, `renovacion`
  {`por_renovar`, `renovadas`} y `tiempo_respuesta` {`solicitudes`, `atendidas`,
  `mediana_horas`}.
- `GET /escaparate` cuenta la visita del día (una por visitante, sin guardar quién).

## Familias y módulos por negocio (ADR 0123)

Módulo `familias`: lo enciende el superadministrador por negocio, apagado por omisión
(`PUT /plataforma/estudios/{estudio}/modulos` `{modulo, habilitado}`; la ficha del negocio
trae `modulos[]`). Apagado, sus rutas responden 403 `MODULE_NOT_ENABLED`. La sesión trae
`estudio.modulos` con los encendidos.

- `GET/PUT /familias` (`miembros.ver` / `estudio.gestionar`): `activo`, `version`,
  `texto_autorizacion` (null = el base) y `texto_vigente`.
- `GET /miembros/{persona}/familia`: `a_su_cargo[]` y `responsables[]` (vínculo con
  `persona`, `parentesco`, `estado` pendiente|activa|retirada y `autorizacion`).
- `POST /miembros/{persona}/participantes` `{participante_id, parentesco}`: 422
  `FAMILY_NOT_ALLOWED` con `meta.motivo` (desactivado, sin_cuenta, participante_invalido,
  ciclo, limite, estado).
- `POST /tutelas/{tutela}/autorizar` `{version}` (en el mostrador) y
  `POST /tutelas/{tutela}/retirar` `{motivo?}`.
- Cuenta: `GET /mi/participantes` (con `meta.version` y `meta.texto`),
  `POST /mi/participantes/{tutela}/autorizar` `{acepto, version}` (409
  `AUTHORIZATION_TEXT_CHANGED` si el texto cambió) y `POST /mi/participantes/{tutela}/retirar`.
- `X-Participante: <ulid>` en `/mi/*` (reservas, planes, pagos y documentos): actúa por
  esa persona; 403 `GUARDIAN_NOT_AUTHORIZED` si no hay autorización vigente. Las líneas de
  orden traen `para` (el participante, si no es quien compró).
- Pago automático del responsable (ADR 0129): `GET /mi/pago-automatico` trae también
  las membresías de sus participantes con autorización vigente, con `para {id, nombre}`,
  y `POST|DELETE /mi/pago-automatico/{acuerdo}` las activa o las quita con su tarjeta.
  `POST /mi/ordenes/{orden}/cobrar` con `X-Participante` admite `domiciliar`: la tarjeta
  queda a nombre del responsable. Retirar la autorización quita esos pagos automáticos.
  En `GET /suscripciones`, `pago_automatico.paga` dice qué responsable la paga; invitar
  al pago automático a quien no tiene correo le llega a su responsable.

## Sitio del negocio (ADR 0114)

Para quien configura el negocio (`estudio.gestionar`), bajo `/api/v1/app/{slug}` (o el
subdominio del negocio):

- `GET /sitio`: `borrador` (`plantilla`, `secciones[]` con `tipo`, `visible`, `titulo`,
  `texto`, `foto_url`; `banners[]` con `id`, `titulo`, `texto`, `enlace_texto`,
  `enlace_url`, `desde`, `hasta`, `foto_url`), `cambios_sin_publicar`, `publicado_en`,
  `pagina_publica`, `tiene_portada` y `catalogo` (plantillas con su orden, qué secciones
  llevan texto y foto, largos y `banners_maximos`).
- `PUT /sitio`: guarda el borrador (422 `VALIDATION_FAILED` con `meta.errors`: tipos de
  otra modalidad o repetidos, enlaces que no son `https://`, `/…` ni `#…`, fotos que no
  se subieron aquí, fechas al revés, más banners que el parámetro). Inicio y contacto
  quedan siempre al principio y al final; una sección que falte se agrega visible.
- `POST /sitio/publicar`, `POST /sitio/descartar`.
- `GET /sitio/vista-previa`: lo mismo que `GET /escaparate`, con el borrador y aunque la
  página aún no esté abierta.
- `POST /sitio/imagenes` (multipart `imagen`: JPG, PNG o WebP de hasta 4 MB): 201 con
  `url`; 422 si el sitio llegó a `sitio.imagenes_maximas`.

`GET /escaparate` suma `sitio`: la versión publicada (o la plantilla esencial con todo
visible si no hay ninguna), con solo las secciones visibles y los banners vigentes hoy
en el negocio.

### Sitio web en Captación y «Plus» (ADR 0167)

- `GET /sitio` suma `publicacion` {`publicado`, `privado`}: abierta, solo con el enlace
  o cerrada (se cambia con `PUT /publicacion`).
- Función de plan `sitio_avanzado` («Plus», desde Premium en citas; los de clases la
  tienen): estilo propio, botón del encabezado, páginas propias y bloques propios.
  Sin ella, `PUT /sitio` y `POST /sitio/vista-previa` responden 403
  `PLAN_FEATURE_NOT_INCLUDED` (`meta.funcion` = `sitio_avanzado`) si se agrega o cambia
  algo de eso. Lo que ya tenía se conserva tal cual y se puede quitar.

### Bloques propios del sitio (ADR 0146)

- En `secciones[]` caben, además, bloques propios con `tipo` = `texto_imagen`,
  `galeria`, `preguntas`, `testimonios`, `llamado`, `video`, `beneficios` o
  `formulario`. Se repiten y van entre la portada y el contacto.
- Cada uno lleva:
  - `id`: lo asigna la API y lo conserva;
  - `visible`, `titulo`, `texto` y `foto_url`, según el tipo;
  - `estilo`: `fondo` (`normal`, `suave`, `marca`, `oscuro`) y `alineacion`
    (`izquierda`, `centro`);
  - `datos`, con campos fijos por tipo: `lado`, `boton_texto`, `boton_enlace`,
    `fotos[]` (`url`, `pie`), `items[]` (`pregunta` y `respuesta`, `nombre` y `texto`,
    o `titulo` y `texto`), `url` (video) o `formulario_id`.
- `PUT /sitio` da 422:
  - con un video que no es de YouTube o Vimeo;
  - con fotos que no se subieron aquí;
  - con un botón sin texto o con un enlace que no es `https://`, `/…` ni `#…`;
  - con un formulario que no existe;
  - con más bloques que `sitio.bloques_maximos` (20 por omisión);
  - con más de 12 fotos o elementos, o más de 20 preguntas.
- `catalogo` suma `bloques`, `bloques_con_texto`, `bloques_con_foto`, `bloques_maximos`,
  `fondos`, `alineaciones`, `max_elementos`, `max_preguntas` y `formularios` (los de
  captación activos: `id`, `nombre`).
- En `GET /escaparate` salen solo los visibles que tienen contenido. Del video, solo
  `proveedor` y `video_id`.

### Varias páginas del sitio (ADR 0146, fase 2)

- El borrador suma:
  - `paginas[]`: `id`, `slug` (la dirección `/p/{slug}`: minúsculas, números y guiones,
    hasta 40, única), `titulo` (60), `en_menu`, `seo` y `secciones[]`. En las secciones
    caben las del sistema (sin `inicio` ni `contacto`, una vez cada una) y bloques;
  - `seo` (de Inicio) y el de cada página: `titulo` (70), `descripcion` (160) e
    `imagen_url` (subida aquí);
  - `pie`: `texto` (300).
- `PUT /sitio` da 422 con una dirección inválida o repetida, una sección del sistema
  repetida o de Inicio en una página, más páginas que `sitio.paginas_maximas` (8) o una
  imagen ajena.
- `catalogo` suma `paginas_maximas`, `movibles` (las secciones del sistema que caben en
  una página), `max_direccion`, `max_titulo_pagina`, `max_seo_titulo`,
  `max_seo_descripcion` y `max_pie`.
- `POST /sitio/publicar` copia el SEO publicado al negocio (`seo_sitio`, control plane).
  De ahí salen la cabecera de `/estudio/{slug}/p/{direccion}`
  (`/api/v1/pagina-negocio/cabeza`) y su lugar en el sitemap.
- `GET /escaparate` suma `seo`, `pie` y `paginas[]`, cada una con solo sus secciones
  visibles que tienen contenido.

### Estilo, botón principal y versiones del sitio (ADR 0146, fase 3)

- El borrador suma:
  - `estilo`: `color` (`#rrggbb` o `null` para usar el de la marca), `tipografia`
    (`predeterminada`, `moderna`, `clasica`, `elegante`, `amigable`, `sistema`),
    `esquinas` (`rectas`, `suaves`, `redondas`) y `tema` (`automatico`, `claro`,
    `oscuro`);
  - `encabezado`: `boton_texto` (40) y `boton_enlace` (300; `https://`, `/…` o `#…`).
- `PUT /sitio` da 422 con un color que no es `#rrggbb`, un valor fuera de la lista o un
  enlace sin texto (`encabezado.boton_texto`).
- `GET /sitio` suma `color_marca`, `versiones[]` (las últimas 10 publicaciones: `id`,
  `publicada_en`, `publicada_por`, `actual`) y, en `catalogo`, `tipografias`, `esquinas`
  y `temas`.
- `POST /sitio/versiones/{id}/restaurar`: copia esa versión al borrador (sin publicarla)
  y devuelve lo mismo que `GET /sitio`. 404 si no está entre las últimas 10.
- `GET /escaparate` suma `estilo` y `encabezado` en `sitio`.
- `POST /sitio/vista-previa` (editor visual): recibe el borrador como en `PUT /sitio`,
  lo revisa con las mismas reglas (422 con `meta.errors`) y devuelve lo mismo que
  `GET /sitio/vista-previa` con ese borrador, sin guardarlo.

