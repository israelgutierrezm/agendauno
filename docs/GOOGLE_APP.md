# Entrar con Google en la app (Android e iOS)

La app usa el paquete oficial `google_sign_in`. Desde Mi perfil se conecta o se quita
Google, y la pantalla de entrar tiene «Entrar con Google». Google no crea cuentas:
solo entra quien ya lo conectó desde su perfil (ADR 0093).

Sin configurar, la app funciona igual pero no muestra nada de Google.

## 1. Clientes OAuth en Google Cloud

En el mismo proyecto del cliente web (el de `GOOGLE_CLIENT_ID` del API y
`VITE_GOOGLE_CLIENT_ID` de la web), en *APIs y servicios → Credenciales → Crear ID de
cliente OAuth*:

- **Android:** paquete `com.agendauno.app` (el `applicationId` de
  `android/app/build.gradle.kts`) y la huella **SHA-1** del certificado de firma. Para
  las compilaciones locales es la de la llave de depuración (`./gradlew
  signingReport`); para Play Store, la de la llave de firma de la aplicación (Play
  Console → Integridad de la app). Puede haber un cliente por cada huella.
- **iOS:** el *bundle ID* `com.agendauno.app` (PRODUCT_BUNDLE_IDENTIFIER en `ios/Runner.xcodeproj`).

## 2. API

Agrega los IDs de cliente de Android e iOS, separados por coma:

```
GOOGLE_CLIENT_ID=<cliente web>
GOOGLE_CLIENT_IDS_APP=<cliente android>,<cliente ios>
```

El API acepta los ID tokens cuyo `aud` sea cualquiera de esos clientes.

## 3. iOS

En `ios/Runner/Info.plist`, agrega el *URL scheme* con el ID de cliente de iOS al revés
(Google lo muestra como «iOS URL scheme»):

```xml
<key>CFBundleURLTypes</key>
<array>
  <dict>
    <key>CFBundleURLSchemes</key>
    <array>
      <string>com.googleusercontent.apps.XXXXXXXX</string>
    </array>
  </dict>
</array>
```

## 4. Compilar la app con los clientes

```
flutter run --dart-define=GOOGLE_SERVER_CLIENT_ID=<cliente web> --dart-define=GOOGLE_IOS_CLIENT_ID=<cliente ios>
```

`GOOGLE_SERVER_CLIENT_ID` es obligatorio: en Android hace que el ID token salga para
el cliente web. `GOOGLE_IOS_CLIENT_ID` solo hace falta para iOS. Para publicar, se
agregan los mismos `--dart-define` a `flutter build appbundle` y a `flutter build ipa`.
