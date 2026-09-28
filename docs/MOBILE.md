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

## Pendiente para publicar

- Firma de release de Android (hoy `build.gradle.kts` firma con la llave de debug)
  y la configuración de iOS.
- Publicar en Google Play y App Store.

## Pruebas

```bash
flutter analyze && flutter test
```
