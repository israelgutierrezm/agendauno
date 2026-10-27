# App móvil (Flutter)

`apps/mobile` — un solo código para las dos apps oficiales (ADR 0111): **AgendaUno**
(`com.agendauno.app`, negocios de clases) y **TurnoUno** (`com.turnouno.app`, negocios
de citas), para todos los roles. AgendaUno además queda lista para apps de marca blanca
(solo Android por ahora, ADR 0208).

## Quién la usa

Se entra al negocio por su slug (sin login global). Con la sesión, la app muestra la
pantalla del rol activo:

| Rol activo | Pantalla | Qué hace |
|---|---|---|
| Alumno o cliente (`miembro`) | `CuentaScreen` | Inicio, reservar clases o agendar citas, reprogramar y cancelar, comprar planes, pagos y pago automático, pase QR, expediente y documentos, privacidad |
| Quien imparte (`instructor`) | `InstructorScreen` | Sus clases y citas, pase de lista, su agenda y sus bloqueos; en citas, con la función de comisiones del plan, «Mis comisiones» (lo ganado, sus propinas y lo por venir del periodo, `GET /mis-comisiones`, ADR 0219) |
| Equipo (dueño, admin, recepción, roles propios) | `EquipoScreen` | Inicio del día, la agenda (con bloqueos), crear y reprogramar citas, el pase de lista completo, escanear el pase QR, cobrar lo pendiente y vender un plan, la caja y la bandeja de mensajes, según sus permisos |

Cualquier rol puede **eliminar su cuenta** desde Perfil (ver «Eliminar la cuenta»).

La app del equipo es la operación del día:
- **Agenda** (`features/agenda`): pase de lista y, en citas, llegada, cobro en caja y
  cancelación.
- **Crear y reprogramar citas**: «Nueva cita» guía con los horarios libres
  (`NuevaCitaSheet`: cliente —se busca o se da de alta—, servicio, profesional, sede,
  día y hora) y «Reprogramar» mueve una cita a otra hora o a otro profesional
  (`ReprogramarCitaSheet`); el servidor revalida el horario.
- **Escanear el pase QR** en la entrada (`features/acceso`), en los negocios que
  controlan la entrada (acceso libre, ADR 0105).
- **Bloqueos** (TurnoUno, `agenda/presentation/bloqueos_agenda.dart`): la línea de
  tiempo del profesional muestra sus bloqueos (comida, ausencia, vacaciones; los de
  su Google u Outlook) y los cierres de sede. Con `agenda.gestionar` se crean por
  horas o por días completos; antes de guardar, la vista previa
  (`/bloqueos/previsualizar`) dice qué citas ya agendadas caen ahí (no se cancelan).
  Con `agenda.eliminar` se quitan (los del calendario de la persona, allá).
- **Caja de la sucursal** (`features/caja`, ADR 0175): abrirla con su fondo, retiros e
  ingresos de efectivo y el cierre con conteo a ciegas.
- **Cobrar y vender** (`features/ventas`): desde «Pendientes» del Inicio, «Por cobrar»
  lista lo pendiente (`GET /ordenes?estado=pendiente`, `ordenes.ver`) y registra el
  pago en caja como la web (`POST /ordenes/{id}/liquidar`, `ordenes.gestionar`): forma
  de pago, sede donde se cobra (con varias, se elige) y referencia; todo en efectivo o
  por transferencia dice antes el precio de contado (ADR 0191,
  `estudio.descuento_contado_pct`). «Vender» (con `productos.ver`) es la venta rápida
  de un plan: persona, plan (bono en TurnoUno), inscripción si le toca, condiciones de
  permanencia, forma de pago y sede; se vende y se cobra de una vez
  (`POST /ordenes` con `liquidar` y la clave del intento).
- **Bandeja** (`features/bandeja`, ADR 0200): WhatsApp, Instagram y Messenger, de 30 en
  30 conversaciones, los mensajes anteriores por tandas (`hay_anteriores`) y, por
  WhatsApp fuera de las 24 horas, una plantilla aprobada con sus datos y su vista
  previa (`/bandeja/plantillas`).

Siguen en la web: capturar las reservas de Fitpass, cobrar en el mostrador lo apartado
para una clase y la administración (catálogo, reportes, configuración).

**Cuenta del cliente** (`features/cuenta`, ADR 0133), como en la web:
- Por pagar:
  - con tarjeta o con ficha de OXXO;
  - en una renovación, dejar el pago automático;
  - avisos del pago en proceso y del modo de prueba.
- Historial de pagos, de 10 en 10.
- La inscripción, antes de comprar.
- Las fichas de datos (formularios) en el Expediente.
- En citas, la pestaña dice «Citas» y nada dice «reserva» ni «clase»: cancelar,
  cambiar de horario, el Inicio, el calendario y el widget hablan de citas. Lo que
  pasa al no asistir habla de su cita y su bono («Pierde 1 cita de su bono»), y el
  corte del bono dice «Agendadas», no «Reservadas».
- Al agendar una cita que se paga para apartarla (o con anticipo), el aviso trae
  «Pagar ahora» si el negocio cobra en línea.
- Al agendar, «¿Tienes un cupón?» (ADR 0220, `CampoCupon`): revisa el código
  (`GET /mi/citas/cupon`), dice cuánto descuenta y lo manda en `cupon`; solo en lo que se
  paga (no con su bono, ni lo ya pagado por adelantado, ni sin precio) y se limpia al
  cambiar de servicio. Lo ya pagado por adelantado (ADR 0218) dice «Ya lo pagaste» y
  no pide anticipo.
- En el Expediente sube fotos o un PDF (el selector del sistema, sin permisos; hasta
  8 MB como el servidor; canal `agendauno/archivos`, método `elegirPdf`).
- El pase de la cartera se renueva («Si pierdes tu teléfono, renuévalo»;
  `POST /mi/pase/wallet/renovar`).
- «Págala en el negocio o desde la web» solo si el negocio cobra en línea.

**Pase de lista** (clases, `agenda/presentation/pase_lista_screen.dart`), como la web:
- quien reservó, con «Llegó», «Llegó tarde» y «No vino» (con su efecto);
- los invitados de los clientes (ADR 0182; «Llegó» / «No vino»,
  `PUT /invitados/{id}/asistencia`);
- quien reservó desde Wellhub, TotalPass o Fitpass (ADR 0202; «Llegó» con
  `checkins.registrar`, TotalPass hace check-in en su app, Fitpass pide revisar la
  identificación; «Cancelar reserva» con `reservas.gestionar`);
- lo apartado para recoger (ADR 0194), por persona: se cobra en el mostrador;
- el cupo cuadra con la lista: ocupan lugar las reservas, los invitados y los
  visitantes de plataformas, como los cuenta el servidor;
- «Agregar <miembro>» (con `reservas.gestionar`): se busca a quien llegó sin reservar
  y se reserva con su plan (`POST /sesiones/{id}/reservas`, clave del intento); llena,
  entra a la lista de espera;
- la lista de espera en su orden, «Ofrecer lugares» y aceptar el lugar ofrecido a
  nombre de la persona.

**Agenda del equipo** (`features/agenda`, ADR 0133):
- La semana se recorre con flechas; «Hoy» regresa. El día vuelve a hoy al cambiar de
  sesión.
- En citas, quien imparte abre su propio día, sin elegir a otros. Al resto se le abre
  el primer profesional con citas ese día.
- Hoja de la cita:
  - lo que se cobra es el total de su orden (`cita.total_minor`, fijo desde que se
    agendó), no el precio de hoy en el catálogo;
  - cobrar en caja, «Llegó» y «No asistió» se confirman antes, con su efecto, como en
    la web; corregir la asistencia también;
  - la asistencia se registra desde `asistencia_desde` (ADR 0101);
  - al cancelar se elige quién cancela (el negocio no penaliza; si lo pidió el cliente,
    aplica su política) y se ve el efecto antes de confirmar;
  - muestra la nota del cliente, quién asiste, su contacto (si se puede ver) y el
    historial de la cita.

Quien tiene varios roles elige con cuál entrar (`ElegirRolScreen`, con el de la
última vez marcado) y cambia desde Perfil → «Cambiar de rol» (ADR 0055). Los
permisos los decide la API con el rol activo.

## Contenido digital: se ve, no se vende (reglas 3.1.1 y 3.1.3 de Apple)

Decisión del dueño: las apps no venden contenido digital (los videos y las clases solo
en línea). Lo ya comprado se ve; se compra en la web, sin decirlo ni enlazarlo en la
app.
- **Videos:** se abren los que incluye su plan; de los demás solo dice «No está
  incluido en tus planes.» (sin nombrar planes ni «Ver planes»).
- **Clase en línea de pago** (`clase.en_linea` con `de_pago`, o `en_linea` en
  antes-de-reservar): no se ofrece reservar ni pagar; dice «Esta clase en línea no se
  reserva desde la app.». Si su plan la incluye, se reserva como siempre.
- **Orden digital** (`digital` en `/mi/ordenes*`): no hay «Pagar» ni OXXO; dice «Este
  pago no se hace desde la app.». El servidor, con `X-App-Producto`, también lo niega.

Lo presencial (planes, bonos, citas, clases en el local) sí se paga en la app: Apple
lo permite fuera de su sistema de compras (3.1.3(e)).

## Entrada

Sin login global (cada negocio con su cuenta), como `/entrar` de la web:
- «Tus negocios»: los últimos 5 que se abrieron en el teléfono (en el almacén cifrado,
  una lista por producto: TurnoUno no ve los de AgendaUno). Un toque deja su
  dirección; se pueden quitar.
- «Buscar mi negocio por nombre»: el directorio de esta app
  (`GET /api/v1/directorio?producto=<clave>&q=…`, los primeros 8).
- O su dirección (slug), como siempre.
En iPhone no se entra con Google (regla 4.8): el acceso dice que quien solo usaba
Google cree su contraseña con «¿Olvidaste tu contraseña?». La marca blanca abre
directo en su negocio.

## Eliminar la cuenta

Perfil → «Eliminar mi cuenta», para cualquier rol (lo piden Apple y Google). Antes de
confirmar se dice el efecto y el plazo: se cierra en todos sus dispositivos y ya no
entra con ella (app ni web); si es cliente, el negocio borra sus datos en el plazo de
la ley y conserva solo lo obligado (pagos y facturas); no se deshace y es solo de ese
negocio. Se confirma con la contraseña (`POST /yo/eliminar-cuenta`
`{password, motivo?}`). Con 200 se cierra la sesión en el teléfono (sin /logout) y el
acceso muestra el mensaje del servidor. El único dueño recibe 422: primero da de baja
el negocio. Quien solo entra con Google crea antes su contraseña (en Perfil o con
«¿Olvidaste tu contraseña?»).

## Abrir en la web

Lo que se hace en la web se abre con la misma sesión, sin escribir la contraseña:
`POST /yo/enlace-web {destino}` da un enlace de un solo uso (2 minutos) que se abre en
el navegador (`abrirEnLaWeb`, `DestinosWeb`):
- el pago automático con una pasarela que captura la tarjeta con su formulario web
  (OpenPay): `/mi-cuenta/pagos#pago-automatico`;
- conectar Google Calendar u Outlook (Perfil, para quien atiende):
  `/mi-perfil#mp-preferencias`.

## Volver a la app después de pagar

Cada app tiene su esquema: `agendauno`, `turnouno` y, en una marca blanca, su
`ANDROID_ID` en minúsculas con «-» en lugar de «_» (`AppConfig.esquemaApp`; Gradle
valida que quede en `^[a-z][a-z0-9.+-]{2,40}$`). Android lo declara en el
intent-filter de `MainActivity` (`${esquemaApp}`, host `pagos`) e iOS en
`CFBundleURLTypes` (`$(APP_URL_SCHEME)` de cada configuración de Runner).
- Al iniciar un pago (`POST /mi/ordenes/{id}/cobrar`) la app manda
  `volver_app: <esquema>`; la página de regreso de la web ofrece «Volver a la app»
  con `<esquema>://pagos?ref=<pago>&pago=exito|pendiente|cancelado`.
- Al abrirse con ese enlace (cerrada o en segundo plano) la app va a Pagos, vuelve a
  leer la cuenta y dice cómo quedó (`core/enlaces/regreso_pago.dart`). Flutter entrega
  el enlace (deep linking activado en Android e iOS) y la app lo atiende antes que el
  Navigator.

## Arquitectura

```
lib/
├── core/        config, red (Dio + bearer), almacenamiento seguro, tema, calendario,
│                avisos legales, enlaces que abren la app (regreso del pago)
└── features/
    ├── auth/           login, elegir rol, sesión (sin registro: ADR 0093)
    ├── cuenta/         la cuenta del alumno o cliente
    ├── agenda/         agenda, cita (nueva, reprogramar, cobrar), pase de lista
    ├── acceso/         escáner del pase QR en la entrada
    ├── caja/           la caja de la sucursal
    ├── ventas/         cobrar lo pendiente y vender un plan (equipo)
    ├── bandeja/        mensajes de WhatsApp, Instagram y Messenger
    ├── instructor/     portal de quien imparte
    ├── inicio/         inicio del equipo
    ├── perfil/         perfil, foto, datos fiscales, cambiar de rol
    ├── notificaciones/ push
    └── widget_inicio/  widget de la pantalla de inicio del cliente
```

Dentro de cada feature: `data/` (repositorios y servicios de API), `application/`
(controladores Riverpod con estado inmutable) y `presentation/` (pantallas con la
menor lógica posible). La app no reimplementa reglas del negocio: pide a la API y
muestra el motivo cuando se niega (`code` del error).

**La sesión solo viaja a su API.** El interceptor de Dio pone el bearer y
`X-Participante` solo si la petición va a la API configurada (mismo esquema, host y
puerto que `API_BASE_URL`, `AppConfig.esDeLaApi`): la consulta a la API del otro
producto (`enOtroProducto`) o cualquier otra URL salen sin ellos.

## Diseño: claro y oscuro (ADR 0147)

La app tiene un solo diseño (los temas a elegir son solo de la web) y sigue el modo del
teléfono: claro u oscuro. Los colores salen de `context.colores`
(`ColoresAgendaUno`, en `core/theme/tema_agendauno.dart`), nunca escritos a mano; un
color pensado para fondo claro (un estado, un servicio) pasa por `par()` o `tinta()`
para que en oscuro se aclare.

Una app de marca blanca cambia el azul por el color de su negocio (`color_marca` de la
sesión: el login y `/yo`; antes de entrar, `COLOR_MARCA` de su archivo). Como el azul,
se oscurece en claro y se aclara en oscuro hasta leerse (4.5:1, `conMarca`). Las apps
oficiales no lo usan.

## Clases o citas y el contrato de la agenda (ADR 0104)

- **Modalidad:** cada negocio es solo de clases o solo de citas. La app la toma de la
  sesión (`estudio.modalidad` y `estudio.capacidades` del login y de `/yo`, en
  `Sesion.modalidad` y `Sesion.capacidades`); no la deduce del giro, del Inicio ni de
  las ofertas.
- **Terminología:** la del negocio (`perfil_config.terminologia`). Si el servidor no la
  manda, la de su modalidad (Cita / Cliente / Profesional en citas) y, sin sesión, la
  de la app (`const Terminologia()`: la de citas en TurnoUno).
- **Tipo de cada sesión:** `TipoSesion` (`lib/core/agenda/contrato_agenda.dart`) lee
  `tipo` (`clase` | `cita`). Uno desconocido o ausente es un error explícito
  (`TipoSesionDesconocido`), nunca una clase por omisión. Tocar una sesión abre lo de
  su tipo, en cualquier vista (`abrirSesion` / `alTocarSesion`): la hoja de la cita o
  el pase de lista de la clase.
- **Lo que calcula el servidor se lee, no se recalcula:** el cupo (`clase`), la
  ocupación que se muestra (`ocupacion.porcentaje`) y, en citas,
  `cita.estado_atencion` y `cita.estado_pago`. Si un API anterior no manda `clase`, el
  cupo sale de los conteos del primer nivel; sin `ocupacion` ni estados no se muestra
  nada en su lugar.
- **Versión mínima:** `/yo` trae `app.version_minima`. La versión de la app es
  `versionApp` (`lib/core/version/version_app.dart`), igual a la de `pubspec.yaml`
  (una prueba lo vigila); al subir una, se sube la otra. Si es más vieja, la app solo
  muestra «Actualiza AgendaUno para continuar». Se revisa al abrir, al entrar y al
  volver a la app. Es una sola para todas las apps, también las de marca blanca (ver
  «Marca blanca»).

## Dos apps oficiales (ADR 0111)

Cada app es un sabor de Android (`productFlavors`, dimensión `producto`). Sin
`--flavor` se compila AgendaUno (`default-flavor` en `pubspec.yaml`).

| | AgendaUno | TurnoUno |
|---|---|---|
| Sabor | `agendauno` | `turnouno` |
| `applicationId` / bundle id | `com.agendauno.app` | `com.turnouno.app` |
| Nombre en el teléfono | AgendaUno | TurnoUno |
| API en release | `https://agendauno.mx` | `https://turnouno.mx` |
| Ícono Android | isotipo (`android/app/src/main/res`) | provisional, su inicial (`android/app/src/turnouno/res`) |
| Ícono iOS | `AppIcon` | `AppIcon-TurnoUno` (provisional, hecho de su ícono de Android) |

```bash
flutter run --flavor turnouno --dart-define=API_BASE_URL=http://10.0.2.2:8000
flutter build appbundle --flavor agendauno
flutter build appbundle --flavor turnouno
```

La app manda `X-App-Producto` en cada petición: la API no le abre negocios del otro
producto. Si alguien escribe la dirección de un negocio del otro producto, la app le
dice cuál descargar.

**iOS** (ADR 0133 y 0208): el proyecto de Xcode ya trae los dos sabores, como Android.
- Configuraciones `Debug-agendauno`, `Release-agendauno` y `Profile-agendauno`, y las
  mismas con `-turnouno`, en el proyecto y en cada target.
- Esquemas compartidos `agendauno` y `turnouno`.
- En cada configuración del target `Runner`:
  - `PRODUCT_BUNDLE_IDENTIFIER` (`com.agendauno.app` o `com.turnouno.app`) y
    `APP_DISPLAY_NAME`, que es el nombre en el teléfono (`CFBundleDisplayName` en
    `ios/Runner/Info.plist`);
  - `ASSETCATALOG_COMPILER_APPICON_NAME`: `AppIcon` o `AppIcon-TurnoUno`;
  - `FLAVOR` (`agendauno` o `turnouno`): archivar desde Xcode compila el Dart del
    mismo producto que el esquema, aunque la última vez se usó el otro
    (`Flutter/Generated.xcconfig` guarda el `--flavor` de la última compilación);
  - `APP_GROUP_ID` (`group.com.agendauno.app` o `group.com.turnouno.app`), para el
    widget (`ios/WIDGET.md`);
  - `TARGETED_DEVICE_FAMILY = 1`: solo iPhone (no se publica para iPad).
- El esquema `Runner` de siempre se queda como AgendaUno.
- `ios/Runner/PrivacyInfo.xcprivacy`: el manifiesto de privacidad (ver «Privacidad y
  documentos legales»).
- La pantalla de arranque usa el fondo del sistema (`systemBackground`): en modo
  oscuro no destella en blanco.

- `ProximoWidget`: el widget de inicio como Widget Extension (ver `ios/WIDGET.md`), con
  sus mismas nueve configuraciones, su id (`<id de la app>.ProximoWidget`), su
  `APP_GROUP_ID` y su `FLAVOR`; Runner lo copia (*Embed Foundation Extensions*, antes
  de *Thin Binary*) y depende de él.
- `Runner.entitlements` y `ProximoWidget.entitlements` llevan el App Group del sabor
  (`$(APP_GROUP_ID)`).
- `APP_URL_SCHEME` (`agendauno` o `turnouno`): el esquema para volver del pago.

```bash
flutter build ipa --flavor agendauno
flutter build ipa --flavor turnouno
```

Sin `--flavor`, `default-flavor` elige `agendauno` también en iOS. Aquí (Windows) no se
compila iOS; el flujo manual «Apps móviles» con plataforma `ios` lo compila sin firmar
en una Mac de GitHub y revisa el id, el nombre, el ícono y que sea solo para iPhone
(`.github/workflows/apps-moviles.yml`). La primera vez en Xcode:
1. elegir el equipo de Apple y la firma en cada configuración;
2. registrar `com.turnouno.app` en el portal de Apple, con Push Notifications.

## Privacidad y documentos legales

- **En la app:** el acceso, Mi perfil y la pestaña Cuenta del cliente enlazan el aviso
  de privacidad y los términos de la plataforma, en la web del producto
  (`https://<dominio>/aviso-de-privacidad` y `/terminos`; el dominio es el de
  `WEB_BASE_URL`, por omisión el de la API). El cliente ve además el aviso de su negocio
  (`/estudio/<slug>/aviso-de-privacidad`; si el negocio aún no lo publica, la página lo
  dice), y el acceso de una marca blanca, el de su negocio. `core/legales/`.
- **iOS:** `PrivacyInfo.xcprivacy` declara que la app no rastrea, los datos que
  recolecta para funcionar y el uso de `UserDefaults` (los suyos y los del App Group
  del widget). La «Privacidad de la app» de cada ficha en App Store Connect y la
  «Seguridad de los datos» de Google Play deben decir lo mismo (tabla de abajo); si la
  app empieza a recolectar algo más, se agrega en los tres lugares.

### Qué declarar en las tiendas

Todo es **para que la app funcione**, **ligado a la cuenta** (salvo las fallas), **sin
rastreo** ni publicidad, y no se vende ni se comparte con terceros para sus fines
(Firebase y la pasarela actúan por cuenta del negocio). Se cifra en tránsito y la
persona puede pedir que se borre (eliminar la cuenta, y ARCO en Privacidad).

| Dato | App Store Connect (Privacidad de la app) | Google Play (Seguridad de los datos) |
|---|---|---|
| Nombre | Información de contacto › Nombre | Información personal › Nombre |
| Correo | Información de contacto › Correo electrónico | Información personal › Dirección de correo |
| Teléfono | Información de contacto › Número de teléfono | Información personal › Número de teléfono |
| Código postal de facturación | Información de contacto › Dirección física | Información personal › Dirección |
| Fecha de nacimiento y género | Información de contacto › Otra información de contacto | Información personal › Otra información |
| Foto de perfil, fotos de documentos | Contenido del usuario › Fotos o videos | Fotos y videos › Fotos |
| Documentos, fichas de datos, reseñas, notas | Contenido del usuario › Otro contenido del usuario | Archivos y documentos; Actividad en la app › Otro contenido generado por el usuario |
| Mensajes de la bandeja (equipo) | Contenido del usuario › Correos o mensajes de texto | Mensajes › Otros mensajes en la app |
| Salud (fichas de un negocio de salud, certificado médico) | Salud y actividad física › Salud | Salud y actividad física › Información de salud |
| Compras y pagos | Compras › Historial de compras | Información financiera › Historial de compras |
| RFC / id fiscal, razón social, régimen | Información financiera › Otra información financiera | Información financiera › Otra información financiera |
| Id de su cuenta | Identificadores › Id de usuario | Información personal › Ids de usuario |
| Token de push | Identificadores › Id del dispositivo | Identificadores del dispositivo u otros identificadores |
| Fallas (sin la persona) | Diagnóstico › Datos de fallas (no ligado) | Información y rendimiento de la app › Registros de fallas |

La app no captura tarjetas (se pagan en la página de la pasarela), no lee la
ubicación ni los contactos. En Google Play, además: la cuenta se elimina desde la app
(Perfil) y desde la web; el enlace para pedirlo sin la app es el aviso de privacidad
del producto.

## Marca blanca (solo AgendaUno, solo Android, preparada, sin publicar)

La app propia de un negocio de AgendaUno es el sabor `agendauno` con el archivo de ese
negocio (ejemplo: `configuraciones/marca_blanca/ejemplo.json`):

```bash
flutter build appbundle --flavor agendauno \
  --dart-define-from-file=configuraciones/marca_blanca/<negocio>.json
```

Abre directo en su negocio (el acceso solo pide correo y contraseña), manda
`X-App-Negocio` (la API solo le abre ese negocio, y solo si es de AgendaUno), no
consulta la app del otro producto y, si su negocio ya no está disponible, dice
«Esta app no está disponible por ahora. Contacta a <nombre>.».

### Su archivo (`configuraciones/marca_blanca/<negocio>.json`)

| Clave | Para qué | Obligatoria |
|---|---|---|
| `NEGOCIO` | El slug del negocio (`^[a-z0-9-]+$`) | Sí |
| `APP_NOMBRE` | Su nombre en el teléfono, en el acceso y en los avisos | Sí, no vacío |
| `ANDROID_ID` | Su `applicationId` (nunca el de una app oficial) | Sí |
| `COLOR_MARCA` | `#RRGGBB`: el fondo de su ícono adaptativo y el tema antes de entrar | No |
| `FIREBASE_ANDROID_APP_ID` (o `FIREBASE_APP_ID`), `FIREBASE_API_KEY` (o `FIREBASE_ANDROID_API_KEY`), `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID` | Su app Android en el proyecto de Firebase (push) | En release, sí |
| `GOOGLE_SERVER_CLIENT_ID` | Entrar con Google (el cliente web, ver abajo) | No |

No lleva `PRODUCTO` (o lleva `agendauno`): la marca blanca es solo de AgendaUno. Tampoco
lleva claves de iOS (`APP_STORE_ID`, `FIREBASE_IOS_APP_ID`, `GOOGLE_IOS_CLIENT_ID`,
`APP_GROUP_ID`): son de las apps oficiales mientras la marca blanca no tenga iOS (ver
«Marca blanca en iOS»).

Gradle (`android/app/build.gradle.kts`) se detiene, con lo que falta, si:
- vienen `ANDROID_ID`, `APP_NOMBRE` o `COLOR_MARCA` sin `NEGOCIO`;
- `NEGOCIO` no es un slug, el sabor es `turnouno`, `PRODUCTO` no es `agendauno`,
  falta `ANDROID_ID` (o es el de una app oficial), `APP_NOMBRE` está vacío o
  `COLOR_MARCA` no es `#RRGGBB`;
- `ANDROID_ID` no tiene forma de id de Android (segmentos separados por puntos, al
  menos dos, cada uno empieza con letra y sigue con letras, números o «_») o es tan
  largo que su esquema de regreso pasa de 41 caracteres;
- el fondo del ícono viene dos veces (`COLOR_MARCA` y `values/colors.xml`);
- en release: falta cualquiera de sus íconos (abajo) o sus datos de Firebase.

En debug basta el archivo: sin sus íconos, usa los de AgendaUno (así compila en el CI
la de ejemplo).

### Sus íconos (`configuraciones/marca_blanca/<negocio>/android/res`)

Los mismos nombres que `android/app/src/main/res`; reemplazan a los de AgendaUno. En
release se exigen todos (`.png` o `.webp`):

| Archivo | Tamaños (mdpi · hdpi · xhdpi · xxhdpi · xxxhdpi) |
|---|---|
| `mipmap-<densidad>/ic_launcher.png` — el ícono completo (Android 7 y antes) | 48 · 72 · 96 · 144 · 192 px |
| `mipmap-<densidad>/ic_launcher_foreground.png` — el frente del ícono adaptativo (el logotipo dentro de los 66 dp centrales de 108 dp, fondo transparente) | 108 · 162 · 216 · 324 · 432 px |
| `mipmap-<densidad>/ic_launcher_monochrome.png` — la silueta para los íconos con tema (Android 13+) | 108 · 162 · 216 · 324 · 432 px |
| `drawable-<densidad>/ic_notificacion.png` — la silueta blanca de la barra de estado (push) | 24 · 36 · 48 · 72 · 96 px |
| `values/colors.xml` con `<color name="ic_launcher_background">#RRGGBB</color>` — o `COLOR_MARCA` en su archivo (uno de los dos) | — |

`mipmap-anydpi-v26/ic_launcher.xml` (el ícono adaptativo) es el de AgendaUno y usa estos
nombres: no hace falta copiarlo.

### Publicarla (requiere autorización)

1. **Su archivo y sus íconos**, arriba. Probarla con
   `flutter run --flavor agendauno --dart-define-from-file=…`.
2. **Firma:** cada marca blanca tiene su propia llave de subida (como en «Firma de
   release»), guardada fuera del repositorio; `android/key.properties` apunta a la de la
   app que se compila. En GitHub: el entorno `marca-blanca-<negocio>` con sus cuatro
   secretos `ANDROID_*`, y el flujo «Apps móviles» con `marca_blanca: <negocio>`
   (solo Android y AgendaUno) firma su `.aab` con su archivo (ver «Compilar en GitHub»).
3. **Google Play:** una app nueva en la cuenta de desarrollador que corresponda (la del
   negocio o la de la plataforma, según el contrato), con su `ANDROID_ID`, su ficha, su
   «Seguridad de los datos» (la misma que la app oficial) y la firma de apps de Google
   Play.
4. **Firebase:** registrar la app Android (`ANDROID_ID`) en el mismo proyecto de las
   oficiales y poner su `FIREBASE_ANDROID_APP_ID` (y la llave, si es otra) en su
   archivo. El servidor envía con la misma cuenta de servicio.
5. **Entrar con Google (opcional):** en el proyecto de Google Cloud del cliente web, un
   cliente OAuth de Android con su `ANDROID_ID` y la huella **SHA-1** de su firma (la
   de la llave de firma de Play Console → Integridad de la app; para probar, la de
   depuración); su id se agrega a `GOOGLE_CLIENT_IDS_APP` del servidor. En su archivo,
   `GOOGLE_SERVER_CLIENT_ID` es el cliente web, el mismo de las apps oficiales
   (`docs/GOOGLE_APP.md`).
6. **APNs:** no aplica mientras la marca blanca no tenga iOS.
7. **Versión mínima:** `app.version_minima` es una para todas las apps. Al subirla,
   también las marcas blancas con una versión más vieja piden actualizarse (abren Play
   con su `ANDROID_ID`). Antes de subirla, publicar la versión nueva de cada marca
   blanca, o sus usuarios se quedan en «Actualiza … para continuar».

### Marca blanca en iOS: no soportada

`flutter build ipa --flavor agendauno --dart-define-from-file=<negocio>.json` saldría
con el bundle id, el nombre y el ícono de AgendaUno. Para que eso no pase, el target
`Runner` tiene una fase «Marca blanca (no en iOS)», antes de compilar el Dart: lee
`DART_DEFINES` de `Flutter/Generated.xcconfig` y, si trae `NEGOCIO`, se detiene con un
error claro.

Para soportarla haría falta:
- configuraciones de Xcode por marca blanca (o un `.xcconfig` generado de su archivo)
  con su `PRODUCT_BUNDLE_IDENTIFIER`, `APP_DISPLAY_NAME`, `APP_GROUP_ID` y su
  `AppIcon-<negocio>`;
- su App ID, su App Group y su perfil en el portal de Apple, con Push Notifications, y
  su llave APNs en Firebase;
- su app iOS en Firebase (`FIREBASE_IOS_APP_ID`, `FIREBASE_IOS_BUNDLE_ID`), su ficha en
  App Store Connect (`APP_STORE_ID`) y, para Google, su cliente iOS y su esquema de URL;
- quitar o ajustar la fase que hoy la detiene.

## Configuración al compilar

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000   # emulador Android
flutter build appbundle --dart-define=API_BASE_URL=https://DOMINIO \
  --dart-define=WEB_BASE_URL=https://DOMINIO
```

- `API_BASE_URL` — la API. Por defecto `http://localhost:8000` en desarrollo y, en una
  compilación de release, la de su producto (`https://agendauno.mx` o
  `https://turnouno.mx`; nunca localhost por olvido).
- `WEB_BASE_URL` — el sitio (fotos del inicio, aviso de privacidad y términos); por
  defecto, la misma que la API.
- `APP_VERSION` — la versión que viaja en los errores de la app al monitoreo de la
  plataforma (ADR 0080); por defecto, la de `pubspec.yaml` (`versionApp`).
- `APP_GROUP_ID` — el App Group de iOS del widget; por defecto `group.` + el id de la
  app oficial.
- Entrar con Google en la app: `GOOGLE_SERVER_CLIENT_ID` y, en iOS,
  `GOOGLE_IOS_CLIENT_ID` (ver `docs/GOOGLE_APP.md`). En iOS hace falta además el
  esquema de URL invertido del cliente en `ios/Runner/Info.plist`
  (`CFBundleURLTypes`); sin él, Google falla en iOS. En el servidor, sus Client ID van
  en `GOOGLE_CLIENT_IDS_APP`.

## Notificaciones push (Firebase)

La app funciona sin Firebase; solo no recibe push. Para activarlas (ADR 0025):

1. Crear un proyecto en la consola de Firebase y registrar en él las apps Android
   (`com.agendauno.app` y `com.turnouno.app`; después, las de marca blanca) y las de
   iOS. Un solo proyecto: el servidor envía a todas con la misma cuenta de servicio.
2. Compilar la app con los datos del proyecto. La app Android y la de iOS tienen cada
   una su id (y a veces su llave); la app elige al correr las de su plataforma, así un
   mismo juego de datos sirve para las dos:

   ```bash
   flutter build appbundle --dart-define=API_BASE_URL=https://DOMINIO \
     --dart-define=FIREBASE_SENDER_ID=... --dart-define=FIREBASE_PROJECT_ID=... \
     --dart-define=FIREBASE_ANDROID_APP_ID=... --dart-define=FIREBASE_IOS_APP_ID=... \
     --dart-define=FIREBASE_API_KEY=... [--dart-define=FIREBASE_IOS_API_KEY=...] \
     [--dart-define=FIREBASE_IOS_BUNDLE_ID=...]
   ```

   `FIREBASE_ANDROID_API_KEY` / `FIREBASE_IOS_API_KEY` solo si la llave de una
   plataforma es otra. `FIREBASE_APP_ID` (y `FIREBASE_API_KEY`) siguen valiendo para la
   plataforma que no traiga los suyos: así se compilaba antes, una vez por plataforma
   (`lib/core/config/firebase_config.dart`).
3. En iOS, la llave APNs del equipo de Apple en Firebase; sin ella las notificaciones
   no llegan a iPhone.
4. En el servidor, en `api.env`: `FCM_CREDENTIALS` (ruta al JSON de la cuenta de
   servicio dentro del volumen `storage`, p. ej. `storage/credenciales/fcm.json`) y,
   opcional, `FCM_PROJECT_ID`.

Al entrar, la app registra el token del teléfono en el negocio
(`dispositivos_push`); al salir lo borra. También al volver a la app (la persona pudo
activar los avisos en Ajustes), sin mandarlo otra vez si no cambió. En iPhone, Firebase
da su token solo cuando ya tiene el de APNs: la app lo espera con reintentos
(`PushController.esperarApns`) y, si no llega, lo intenta la próxima vez. En Android
las notificaciones van en el canal propio de la app («Avisos del negocio»,
`res/values/avisos.xml`; lo crea `MainActivity` y FCM lo usa con
`default_notification_channel_id`).

Al tocar una notificación, la app abre lo suyo, según su `tipo` (la clave de su
plantilla), también cuando la abrió desde cerrada:
- de una reserva o una cita (`reserva.*`): sus reservas o citas para el cliente, y la
  agenda para el equipo y quien imparte;
- de un cobro, un pago o su plan (`orden.*`, `pago.*`, `cobro.*`, `membresia.*`): sus
  pagos;
- lo demás, el Inicio.

Una notificación de otro negocio no cambia la pantalla.

## Compilar en GitHub (Apps móviles)

`.github/workflows/apps-moviles.yml`, solo a mano. No sube nada a las tiendas.

| Entorno | Para | Secretos y variables |
|---|---|---|
| `android-agendauno`, `android-turnouno` | el `.aab` firmado de la app oficial | `ANDROID_KEYSTORE_BASE64`, `ANDROID_STORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`; `FIREBASE_API_KEY`, `FIREBASE_APP_ID` (o `FIREBASE_ANDROID_APP_ID`), `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID`; variable opcional `GOOGLE_SERVER_CLIENT_ID` |
| `marca-blanca-<negocio>` | el `.aab` firmado de una marca blanca (`marca_blanca: <negocio>`) | los cuatro `ANDROID_*` de su llave; su Firebase va en su archivo `configuraciones/marca_blanca/<negocio>.json` |
| `ios-agendauno`, `ios-turnouno` | la compilación de iOS sin firmar, con lo que lleva al App Store | variable `APP_STORE_ID` (el id numérico de su ficha); `FIREBASE_API_KEY` (o `FIREBASE_IOS_API_KEY`), `FIREBASE_IOS_APP_ID`, `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID` |

- Un paquete para la tienda sin Firebase no se compila: la app saldría sin
  notificaciones push (antes solo era un aviso).
- iOS exige `APP_STORE_ID` de su sabor (la pantalla «Actualiza la app» abre su ficha) y
  revisa en lo compilado: id, nombre, ícono, solo iPhone, el esquema de regreso, el
  widget (`PlugIns/ProximoWidget.appex` con su id, su App Group y su sabor) y el
  manifiesto de privacidad.
- La marca blanca: el flujo revisa antes que su archivo exista, que su `NEGOCIO` sea
  el pedido y que traiga su Firebase; Gradle revisa lo demás.

## Firma de release (Android)

Google Play pide una llave de subida propia. Se crea una sola vez y se guarda fuera del
repositorio (si se pierde, hay que pedir a Google que la reemplace):

```bash
keytool -genkey -v -keystore agendauno-subida.jks -keyalg RSA -keysize 2048 -validity 10000 -alias subida
```

Copia `android/key.properties.example` a `android/key.properties` (ignorado por git) y
llénalo con la ruta del `.jks`, el alias y las contraseñas. Con ese archivo,
`flutter build appbundle` firma para Play; sin él, la versión de release no se compila
(el error dice qué falta), para no subir por descuido una firmada con la llave de
depuración. Para probar en un teléfono sin la llave, usa `flutter run` o
`flutter build apk --debug`.

En iOS la v1 no ofrece entrar con Google (pediría registrar su esquema de URL y, por la
regla 4.8 de Apple, ofrecer también «Iniciar sesión con Apple»); en Android sí. La
pantalla «Actualiza la app» abre Play y, en iOS, la App Store si se compila con
`--dart-define=APP_STORE_ID=…`.

## Pendiente para publicar

- Crear la llave de subida (arriba) y la configuración de iOS (equipo y perfil en
  Xcode). iOS mínimo: 15. En Xcode, la capacidad **Push Notifications** (ya está
  `Runner.entitlements` con `aps-environment`) y, en Firebase, la llave APNs del
  equipo de Apple; sin ella las notificaciones no llegan a iPhone.
- Los App Groups (`group.com.agendauno.app`, `group.com.turnouno.app`) en el portal de
  Apple, activados en los App IDs de las dos apps y de sus widgets
  (`….ProximoWidget`), y verificar el widget en una Mac (`ios/WIDGET.md`).
- Los entornos de GitHub de «Compilar en GitHub» (arriba).
- Compilar firmado y probar la versión de iOS en una Mac, con los esquemas de las dos
  apps (arriba): firma y `com.turnouno.app` en el portal de Apple.
- Llenar la «Privacidad de la app» de App Store Connect y la «Seguridad de los datos» de
  Google Play igual que `PrivacyInfo.xcprivacy`.
- Logotipo e íconos definitivos de TurnoUno (hoy son provisionales: su inicial; el de
  iOS se hizo de su ícono de Android de 432 px y el de 1024 px se ve suave).
- Íconos de las tiendas: hoy salen del isotipo de 192 px ampliado (el de 1024 px se
  ve suave y con el trazo cortado). Antes de publicar, generarlos desde el isotipo
  en vector o a 1024 px.
- Publicar las dos apps en Google Play y App Store (requiere autorización).

## Pruebas

```bash
flutter analyze && flutter test
flutter test --dart-define=PRODUCTO=turnouno
flutter test test/marca_blanca_test.dart \
  --dart-define-from-file=configuraciones/marca_blanca/ejemplo.json
```

La suite no depende del producto con que se compila: CI la corre como AgendaUno y
como TurnoUno (ADR 0133). Una prueba de clases dice `modalidad: Modalidad.clases`;
sin ella, la sesión toma la de la app (citas en TurnoUno). `marca_blanca_test.dart`
solo corre con el archivo de una marca blanca (sin él se salta); el CI también compila
el APK de la de ejemplo y comprueba que con `--flavor turnouno` no compila.
