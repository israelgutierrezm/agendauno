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
`permanencia_hasta`, `modo`, `meses_restantes`, `precio_base_minor`, `penalizacion_minor`,
`adeudo_minor`; al dar de baja, `motivo`, `exentar_penalizacion`, `conservar_adeudo`).

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

