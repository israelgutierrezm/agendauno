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

## Configuración al compilar

```bash
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000   # emulador Android
flutter build appbundle --dart-define=API_BASE_URL=https://DOMINIO \
  --dart-define=WEB_BASE_URL=https://DOMINIO
```

- `API_BASE_URL` — la API (por defecto `http://localhost:8000`).
- `WEB_BASE_URL` — el sitio (fotos del inicio); por defecto, la misma que la API.

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
  Xcode).
- Publicar en Google Play y App Store.

## Pruebas

```bash
flutter analyze && flutter test
```
