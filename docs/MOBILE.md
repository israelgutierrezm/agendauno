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
| Quien imparte (`instructor`) | `InstructorScreen` | Sus clases y citas, pase de lista |
| Equipo (dueño, admin, recepción, roles propios) | `EquipoScreen` | Agenda del día y operación permitida por sus permisos |

Quien tiene varios roles elige con cuál entrar (`ElegirRolScreen`, con el de la
última vez marcado) y cambia desde Perfil → «Cambiar de rol» (ADR 0055). Los
permisos los decide la API con el rol activo.

## Arquitectura

```
lib/
├── core/        config, red (Dio + bearer), almacenamiento seguro, tema, calendario
└── features/
    ├── auth/           login, registro, elegir rol, sesión
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

**iOS** (en una Mac, antes de publicar TurnoUno): en Xcode, duplicar las
configuraciones `Debug`, `Release` y `Profile` como `Debug-turnouno`,
`Release-turnouno` y `Profile-turnouno` (y las de AgendaUno como `*-agendauno`), crear
los esquemas `agendauno` y `turnouno` (compartidos) y, en las de TurnoUno, poner
`PRODUCT_BUNDLE_IDENTIFIER = com.turnouno.app` y el nombre `TurnoUno` en
`CFBundleDisplayName`. Así `flutter build ipa --flavor turnouno` funciona igual que
en Android. Mientras tanto, `--dart-define=PRODUCTO=turnouno` elige el producto en
Dart (no cambia el bundle id).

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
  con los esquemas de las dos apps (arriba).
- Logotipo e íconos definitivos de TurnoUno (hoy son provisionales: su inicial).
- Íconos de las tiendas: hoy salen del isotipo de 192 px ampliado (el de 1024 px se
  ve suave y con el trazo cortado). Antes de publicar, generarlos desde el isotipo
  en vector o a 1024 px.
- Publicar las dos apps en Google Play y App Store (requiere autorización).

## Pruebas

```bash
flutter analyze && flutter test
```
