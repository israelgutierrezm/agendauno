# Widget de inicio en iOS

El widget de la pantalla de inicio del cliente muestra su próxima reserva o cita (qué,
cuándo y dónde) y su saldo o plan. En Android está completo
(`android/app/src/main/kotlin/com/agendauno/app/WidgetProximo.kt`). En iOS ya es un
target del proyecto de Xcode (`ProximoWidget`, una Widget Extension), hecho aquí sin
Xcode: **falta verificarlo en una Mac** (pasos abajo) antes de anunciarlo para iPhone.
Mientras tanto la landing dice «En Android, su próxima clase (o cita) en la pantalla
de inicio» (`landing.{clases,citas}.novedades.items.app` en
`apps/web/src/i18n/locales/es-MX.ts`); al terminar la prueba se quita «En Android».

## Lo que ya está

- **Dart** (`lib/features/widget_inicio/`):
  - `HomeWidget.setAppGroupId(AppConfig.appGroupId)`: el `--dart-define=APP_GROUP_ID`
    o, por omisión, `group.com.agendauno.app` o `group.com.turnouno.app`, según el
    sabor (`ProductoApp.actual`).
  - Guarda las claves de `ClavesWidget` (`au_negocio`, `au_proximas`,
    `au_plan_titulo`, `au_plan`, `au_vacio`, `au_accion`) y pide redibujar el widget de
    `kind` `ProximoWidget`: al cargar Mi cuenta (abrir la app, volver a ella, reservar,
    cancelar, pagar) y con un aviso push que llega con la app abierta. Al cerrar
    sesión, borra los datos.
- **El target `ProximoWidget`** en `ios/Runner.xcodeproj/project.pbxproj`:
  - `com.apple.product-type.app-extension`, con `ios/ProximoWidget/ProximoWidget.swift`
    (el único `@main`), su `Info.plist` y su `ProximoWidget.entitlements`.
  - Las mismas nueve configuraciones que `Runner` (`Debug`, `Release`, `Profile` y
    las `-agendauno` y `-turnouno`), sobre los mismos `Flutter/Debug.xcconfig` y
    `Flutter/Release.xcconfig` (su versión es la de la app: `FLUTTER_BUILD_NAME` y
    `FLUTTER_BUILD_NUMBER`, como pide el App Store).
  - En cada una: `PRODUCT_BUNDLE_IDENTIFIER` `com.agendauno.app.ProximoWidget` o
    `com.turnouno.app.ProximoWidget`, `APP_GROUP_ID` y `FLAVOR` del sabor,
    `IPHONEOS_DEPLOYMENT_TARGET` 15.0, `TARGETED_DEVICE_FAMILY` 1, `SKIP_INSTALL` y
    `APPLICATION_EXTENSION_API_ONLY`.
  - Su `Info.plist`: `NSExtensionPointIdentifier` `com.apple.widgetkit-extension`,
    `AppGroupId` (`$(APP_GROUP_ID)`, el UserDefaults de la app) y `AppProducto`
    (`$(FLAVOR)`: con `turnouno` dice «cita»).
- **Runner**: depende del widget y lo copia en `Runner.app/PlugIns` (*Embed Foundation
  Extensions*, puesta antes de *Thin Binary* para que Xcode no marque un ciclo).
  `Runner.entitlements` lleva `com.apple.security.application-groups` →
  `$(APP_GROUP_ID)`, además de `aps-environment`.
- **CI**: «Apps móviles» con `plataforma: ios` revisa que `PlugIns/ProximoWidget.appex`
  salga con su id, su App Group y su sabor.

## Verificarlo en una Mac (una vez)

1. `cd apps/mobile && flutter pub get`, y abrir `ios/Runner.xcworkspace`.
2. En el navegador del proyecto debe verse el grupo `ProximoWidget` y, en *Targets*,
   `Runner`, `RunnerTests` y `ProximoWidget`. Si Xcode ofrece «Update to recommended
   settings», aceptar solo lo del target `ProximoWidget` y revisar que no cambie
   `PRODUCT_BUNDLE_IDENTIFIER`, `APP_GROUP_ID` ni `FLAVOR`.
3. Target `ProximoWidget` → *Build Settings*, en *Release-agendauno* y
   *Release-turnouno*: el id, `APP_GROUP_ID` y `FLAVOR` de cada sabor (arriba).
4. *Signing & Capabilities*, en `Runner` y en `ProximoWidget`, para cada sabor:
   - elegir el equipo de Apple (firma automática);
   - **App Groups**: debe aparecer el del sabor (viene del `.entitlements`); si Xcode
     lo pide, activarlo con el botón «+» del grupo (`group.com.agendauno.app` o
     `group.com.turnouno.app`).
5. En el portal de Apple Developer:
   - registrar los App Groups `group.com.agendauno.app` y `group.com.turnouno.app`;
   - los App IDs `com.agendauno.app.ProximoWidget` y `com.turnouno.app.ProximoWidget`;
   - activar App Groups en los cuatro App IDs (las dos apps y sus widgets) y regenerar
     los perfiles (con firma automática, Xcode lo hace).
6. Compilar las dos apps sin firmar, como el CI:
   ```bash
   flutter build ios --release --no-codesign --flavor agendauno
   flutter build ios --release --no-codesign --flavor turnouno
   ```
   Si sale «Cycle inside Runner», revisar que *Embed Foundation Extensions* esté antes
   de *Thin Binary* en *Build Phases* de `Runner`.
7. Revisar lo compilado (lo mismo que el CI):
   ```bash
   /usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' \
     build/ios/iphoneos/Runner.app/PlugIns/ProximoWidget.appex/Info.plist
   /usr/libexec/PlistBuddy -c 'Print :AppGroupId' \
     build/ios/iphoneos/Runner.app/PlugIns/ProximoWidget.appex/Info.plist
   ```
   Deben ser `com.<sabor>.app.ProximoWidget` y `group.com.<sabor>.app`.
8. Probarlo en un iPhone (abajo) con `flutter run --flavor agendauno` y con
   `--flavor turnouno`.
9. Archivar desde Xcode con los esquemas `agendauno` y `turnouno` (*Product → Archive*):
   el archivo debe incluir la extensión y pasar la validación de App Store Connect.

## Probarlo

1. Entrar como cliente y abrir Mi cuenta.
2. Agregar el widget «Mi próxima reserva» («Mi próxima cita» en TurnoUno) a la
   pantalla de inicio.
3. Reservar o cancelar en la app: el widget cambia al momento.
4. Con una clase ya terminada, el widget pasa solo a la siguiente.
5. Al cerrar sesión, el widget pide abrir la app.

## Notas

- Si el App Group no está activo en el portal, la app sigue igual: home_widget falla
  en silencio y el widget no muestra datos.
- La marca blanca no tiene iOS por ahora (MOBILE.md, «Marca blanca en iOS»): su
  compilación se detiene antes de compilar. Cuando lo tenga, llevará su propio
  `APP_GROUP_ID` (el `--dart-define` y el de sus configuraciones de Xcode) y el id de
  su widget.
- Con la app cerrada, un push no actualiza el widget: eso pediría leer la cuenta en
  segundo plano. Se actualiza en cuanto se abre la app.
- El widget solo muestra lo que el cliente ya ve en su Inicio: la clase o el servicio,
  la hora, la sede y el saldo. Nunca el profesional ni la persona a su cargo.
