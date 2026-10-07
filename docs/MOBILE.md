# App móvil (Flutter)

`apps/mobile` — una sola app (`com.agendauno.app`) para todos los negocios y roles.

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

## Configuración al compilar

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000   # emulador Android
flutter build appbundle --dart-define=API_BASE_URL=https://DOMINIO \
  --dart-define=WEB_BASE_URL=https://DOMINIO
```

- `API_BASE_URL` — la API (por defecto `http://localhost:8000`).
- `WEB_BASE_URL` — el sitio (fotos del inicio); por defecto, la misma que la API.
- `APP_VERSION` — la versión que viaja en los errores de la app al monitoreo de la
  plataforma (ADR 0080); por defecto `dev`.

## Notificaciones push (Firebase)

La app funciona sin Firebase; solo no recibe push. Para activarlas (ADR 0025):

1. Crear un proyecto en la consola de Firebase y registrar la app Android
   (`com.agendauno.app`) y, si aplica, la de iOS.
2. Compilar la app con los datos del proyecto:

   ```bash
   flutter build appbundle --dart-define=API_BASE_URL=https://DOMINIO \
     --dart-define=FIREBASE_API_KEY=... --dart-define=FIREBASE_APP_ID=... \
     --dart-define=FIREBASE_SENDER_ID=... --dart-define=FIREBASE_PROJECT_ID=...
   ```

   En iOS se agrega `--dart-define=FIREBASE_IOS_BUNDLE_ID=...`.
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
`flutter build appbundle` firma para Play; sin él, la versión de release se firma con
la llave de depuración (sirve para probar en un teléfono, Play la rechaza).

## Pendiente para publicar

- Crear la llave de subida (arriba) y la configuración de iOS (equipo y perfil en
  Xcode). iOS mínimo: 15. En Xcode, la capacidad **Push Notifications** (ya está
  `Runner.entitlements` con `aps-environment`) y, en Firebase, la llave APNs del
  equipo de Apple; sin ella las notificaciones no llegan a iPhone.
- Compilar y probar la versión de iOS en una Mac (aquí solo se compila Android).
- Íconos de las tiendas: hoy salen del isotipo de 192 px ampliado (el de 1024 px se
  ve suave y con el trazo cortado). Antes de publicar, generarlos desde el isotipo
  en vector o a 1024 px.
- Publicar en Google Play y App Store.

## Pruebas

```bash
flutter analyze && flutter test
```
