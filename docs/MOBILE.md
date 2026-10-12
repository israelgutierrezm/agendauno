# App móvil (Flutter)

`apps/mobile` — un solo código para las dos apps oficiales (ADR 0111): **AgendaUno**
(`com.agendauno.app`, negocios de clases) y **TurnoUno** (`com.turnouno.app`, negocios
de citas), para todos los roles. AgendaUno además queda lista para apps de marca blanca.

## Quién la usa

Se entra al negocio por su slug (sin login global). Con la sesión, la app muestra la
pantalla del rol activo:

| Rol activo | Pantalla | Qué hace |
|---|---|---|
| Alumno o cliente (`miembro`) | `CuentaScreen` | Inicio, reservar clases o agendar citas, reprogramar y cancelar, comprar planes, pagos y pago automático, pase QR, expediente y documentos, privacidad |
| Quien imparte (`instructor`) | `InstructorScreen` | Sus clases y citas, pase de lista, su agenda |
| Equipo (dueño, admin, recepción, roles propios) | `EquipoScreen` | Inicio del día y la agenda: pase de lista y, en citas, llegada, cobro en caja y cancelación, según sus permisos |

La app del equipo es la operación del día. Crear o reprogramar citas, agregar a una
clase, escanear el pase QR y registrar Wellhub/TotalPass siguen en la web.

**Cuenta del cliente** (`features/cuenta`, ADR 0133), como en la web:
- Por pagar:
  - con tarjeta o con ficha de OXXO;
  - en una renovación, dejar el pago automático;
  - avisos del pago en proceso y del modo de prueba.
- Historial de pagos, de 10 en 10.
- La inscripción, antes de comprar.
- Las fichas de datos (formularios) en el Expediente.
- En citas, la pestaña dice «Citas».

**Pase de lista:** la lista de espera en su orden, «Ofrecer lugares» y aceptar el
lugar ofrecido a nombre de la persona.

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

## Arquitectura

```
lib/
├── core/        config, red (Dio + bearer), almacenamiento seguro, tema, calendario
└── features/
    ├── auth/           login, elegir rol, sesión (sin registro: ADR 0093)
    ├── cuenta/         la cuenta del alumno
    ├── agenda/         agenda, cita, pase de lista
    ├── instructor/     portal de quien imparte
    ├── inicio/         inicio del equipo
    ├── perfil/         perfil, foto, cambiar de rol
    └── notificaciones/ push
```

Dentro de cada feature: `data/` (repositorios y servicios de API), `application/`
(controladores Riverpod con estado inmutable) y `presentation/` (pantallas con la
menor lógica posible). La app no reimplementa reglas del negocio: pide a la API y
muestra el motivo cuando se niega (`code` del error).

## Diseño: claro y oscuro (ADR 0147)

La app tiene un solo diseño (los temas a elegir son solo de la web) y sigue el modo del
teléfono: claro u oscuro. Los colores salen de `context.colores`
(`ColoresAgendaUno`, en `core/theme/tema_agendauno.dart`), nunca escritos a mano; un
color pensado para fondo claro (un estado, un servicio) pasa por `par()` o `tinta()`
para que en oscuro se aclare.

## Clases o citas y el contrato de la agenda (ADR 0104)

- **Modalidad:** cada negocio es solo de clases o solo de citas. La app la toma de la
  sesión (`estudio.modalidad` y `estudio.capacidades` del login y de `/yo`, en
  `Sesion.modalidad` y `Sesion.capacidades`); no la deduce del giro, del Inicio ni de
  las ofertas.
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
  volver a la app.

## Dos apps oficiales (ADR 0111)

Cada app es un sabor de Android (`productFlavors`, dimensión `producto`). Sin
`--flavor` se compila AgendaUno (`default-flavor` en `pubspec.yaml`).

| | AgendaUno | TurnoUno |
|---|---|---|
| Sabor | `agendauno` | `turnouno` |
| `applicationId` | `com.agendauno.app` | `com.turnouno.app` |
| Nombre en el teléfono | AgendaUno | TurnoUno |
| API en release | `https://agendauno.mx` | `https://turnouno.mx` |
| Ícono | isotipo (`android/app/src/main/res`) | provisional, su inicial (`android/app/src/turnouno/res`) |

```bash
flutter run --flavor turnouno --dart-define=API_BASE_URL=http://10.0.2.2:8000
flutter build appbundle --flavor agendauno
flutter build appbundle --flavor turnouno
```

La app manda `X-App-Producto` en cada petición: la API no le abre negocios del otro
producto. Si alguien escribe la dirección de un negocio del otro producto, la app le
dice cuál descargar.

**iOS** (ADR 0133): el proyecto de Xcode ya trae los dos sabores, como Android.
- Configuraciones `Debug-agendauno`, `Release-agendauno` y `Profile-agendauno`, y las
  mismas con `-turnouno`, en el proyecto y en cada target.
- Esquemas compartidos `agendauno` y `turnouno`.
- En cada configuración, su `PRODUCT_BUNDLE_IDENTIFIER` (`com.agendauno.app` o
  `com.turnouno.app`) y `APP_DISPLAY_NAME`, que es el nombre en el teléfono
  (`CFBundleDisplayName` en `ios/Runner/Info.plist`).
- El esquema `Runner` de siempre se queda como AgendaUno.

```bash
flutter build ipa --flavor agendauno
flutter build ipa --flavor turnouno
```

Sin `--flavor`, `default-flavor` elige `agendauno` también en iOS.

Falta probarlo en una Mac: aquí solo se compila Android. La primera vez en Xcode:
1. elegir el equipo de Apple y la firma en cada configuración;
2. registrar `com.turnouno.app` en el portal de Apple, con Push Notifications.

Las apps de marca blanca son solo para Android por ahora: en iOS, el sabor
`agendauno` lleva el id de la app oficial.

## Marca blanca (solo AgendaUno, preparada, sin publicar)

La app propia de un negocio de AgendaUno es el sabor `agendauno` con el archivo de ese
negocio (ejemplo: `configuraciones/marca_blanca/ejemplo.json`):

```bash
flutter build appbundle --flavor agendauno \
  --dart-define-from-file=configuraciones/marca_blanca/<negocio>.json
```

- `NEGOCIO` — el slug: la app abre directo en él (el acceso solo pide correo y
  contraseña) y manda `X-App-Negocio`; la API solo le abre ese negocio.
- `APP_NOMBRE` — su nombre en el teléfono y en la app.
- `ANDROID_ID` — su `applicationId` propio (obligatorio: Gradle no compila una marca
  blanca con el id de una app oficial ni con el sabor de TurnoUno).
- `APP_STORE_ID`, `FIREBASE_*` — los de su ficha en el App Store y su app en Firebase.
- Íconos: `configuraciones/marca_blanca/<negocio>/android/res` (mismos nombres que
  `android/app/src/main/res`) reemplazan a los de AgendaUno.

Publicar una marca blanca requiere autorización (y la cuenta de desarrollador que
corresponda).

## Configuración al compilar

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000   # emulador Android
flutter build appbundle --dart-define=API_BASE_URL=https://DOMINIO \
  --dart-define=WEB_BASE_URL=https://DOMINIO
```

- `API_BASE_URL` — la API. Por defecto `http://localhost:8000` en desarrollo y, en una
  compilación de release, la de su producto (`https://agendauno.mx` o
  `https://turnouno.mx`; nunca localhost por olvido).
- `WEB_BASE_URL` — el sitio (fotos del inicio); por defecto, la misma que la API.
- `APP_VERSION` — la versión que viaja en los errores de la app al monitoreo de la
  plataforma (ADR 0080); por defecto, la de `pubspec.yaml` (`versionApp`).
- Entrar con Google en la app: `GOOGLE_SERVER_CLIENT_ID` y, en iOS,
  `GOOGLE_IOS_CLIENT_ID` (ver `docs/GOOGLE_APP.md`). En iOS hace falta además el
  esquema de URL invertido del cliente en `ios/Runner/Info.plist`
  (`CFBundleURLTypes`); sin él, Google falla en iOS. En el servidor, sus Client ID van
  en `GOOGLE_CLIENT_IDS_APP`.

## Notificaciones push (Firebase)

La app funciona sin Firebase; solo no recibe push. Para activarlas (ADR 0025):

1. Crear un proyecto en la consola de Firebase y registrar en él las apps Android
   (`com.agendauno.app` y `com.turnouno.app`; después, las de marca blanca) y, si
   aplica, las de iOS. Un solo proyecto: el servidor envía a todas con la misma
   cuenta de servicio, y cada compilación lleva el `FIREBASE_APP_ID` de su app.
2. Compilar la app con los datos del proyecto:

   ```bash
   flutter build appbundle --dart-define=API_BASE_URL=https://DOMINIO \
     --dart-define=FIREBASE_API_KEY=... --dart-define=FIREBASE_APP_ID=... \
     --dart-define=FIREBASE_SENDER_ID=... --dart-define=FIREBASE_PROJECT_ID=...
   ```

   En iOS se agrega `--dart-define=FIREBASE_IOS_BUNDLE_ID=...`. `FIREBASE_APP_ID` y
   `FIREBASE_API_KEY` son distintos para Android e iOS: se compila una vez por
   plataforma con los suyos.
3. En el servidor, en `api.env`: `FCM_CREDENTIALS` (ruta al JSON de la cuenta de
   servicio dentro del volumen `storage`, p. ej. `storage/credenciales/fcm.json`) y,
   opcional, `FCM_PROJECT_ID`.

Al entrar, la app registra el token del teléfono en el negocio
(`dispositivos_push`); al salir lo borra.

Al tocar una notificación, la app abre lo suyo, según su `tipo` (la clave de su
plantilla), también cuando la abrió desde cerrada:
- de una reserva o una cita (`reserva.*`): sus reservas o citas para el cliente, y la
  agenda para el equipo y quien imparte;
- de un cobro, un pago o su plan (`orden.*`, `pago.*`, `cobro.*`, `membresia.*`): sus
  pagos;
- lo demás, el Inicio.

Una notificación de otro negocio no cambia la pantalla.

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
- Compilar y probar la versión de iOS en una Mac (aquí solo se compila Android),
  con los esquemas de las dos apps (arriba): firma, `com.turnouno.app` en el portal
  de Apple y sus íconos.
- Logotipo e íconos definitivos de TurnoUno (hoy son provisionales: su inicial).
- Íconos de las tiendas: hoy salen del isotipo de 192 px ampliado (el de 1024 px se
  ve suave y con el trazo cortado). Antes de publicar, generarlos desde el isotipo
  en vector o a 1024 px.
- Publicar las dos apps en Google Play y App Store (requiere autorización).

## Pruebas

```bash
flutter analyze && flutter test
flutter test --dart-define=PRODUCTO=turnouno
```

La suite no depende del producto con que se compila: CI la corre como AgendaUno y
como TurnoUno (ADR 0133). Una prueba de clases dice `modalidad: Modalidad.clases`;
sin ella, la sesión toma la de la app (citas en TurnoUno).
