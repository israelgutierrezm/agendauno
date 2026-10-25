# Widget de inicio en iOS (pendiente de Xcode)

El widget de la pantalla de inicio del cliente muestra su próxima reserva o cita (qué,
cuándo y dónde) y su saldo o plan. En Android ya está completo
(`android/app/src/main/kotlin/com/agendauno/app/WidgetProximo.kt`). En iOS falta crear
la Widget Extension en una Mac: aquí no hay Xcode y editar `project.pbxproj` a mano
es riesgoso.

Puede quedar fuera de la V1, pero mientras no se compile y se pruebe en un iPhone no
se anuncia para iPhone: la landing dice «En Android, su próxima clase (o cita) en la
pantalla de inicio» (`landing.{clases,citas}.novedades.items.app` en
`apps/web/src/i18n/locales/es-MX.ts`).
Al terminar los pasos de abajo y la prueba, se quita «En Android».

## Lo que ya está listo

- **Dart** (`lib/features/widget_inicio/`):
  - `HomeWidget.setAppGroupId(AppConfig.appGroupId)`: el `--dart-define=APP_GROUP_ID`
    o, por omisión, `group.com.agendauno.app` o `group.com.turnouno.app`, según el
    sabor (`ProductoApp.actual`).
  - Guarda las claves de `ClavesWidget` (`au_negocio`, `au_proximas`,
    `au_plan_titulo`, `au_plan`, `au_vacio`, `au_accion`).
  - Pide redibujar el widget de `kind` `ProximoWidget`.
  - Lo hace al cargar Mi cuenta (abrir la app, volver a ella, reservar, cancelar,
    pagar) y con un aviso push que llega con la app abierta. Al cerrar sesión, borra
    los datos.
- **Swift**: `ios/ProximoWidget/ProximoWidget.swift` es el widget (WidgetKit). Todavía
  no pertenece a ningún target. Sus textos fijos siguen al producto (`AppProducto` de
  su Info.plist): «Mi próxima cita» en TurnoUno, «Mi próxima reserva» en AgendaUno,
  como en Android.
- **Target `Runner`** (ADR 0208): cada configuración ya tiene `APP_GROUP_ID`
  (`group.com.agendauno.app` en las de AgendaUno y en `Debug`/`Release`/`Profile`,
  `group.com.turnouno.app` en las `-turnouno`) y `FLAVOR`.

## Pasos en Xcode (una vez)

1. Abrir `ios/Runner.xcworkspace`.
2. *File → New → Target… → Widget Extension*.
   - Nombre: `ProximoWidget`.
   - Sin *Live Activity* ni *Configuration App Intent*.
   - Lenguaje Swift, *Embed in Application*: `Runner`.
   - Si pregunta si activar el esquema, «Cancel»: se compila con los esquemas
     `agendauno` y `turnouno`.
3. Borrar los `.swift` que generó Xcode en el grupo `ProximoWidget`. Agregar a ese
   target `ios/ProximoWidget/ProximoWidget.swift` (*Target Membership*: solo
   `ProximoWidget`). Debe quedar un solo `@main`.
4. En el target `ProximoWidget`, crear las configuraciones de los sabores, igual que en
   `Runner`: `Debug-agendauno`, `Release-agendauno`, `Profile-agendauno` y las tres
   `-turnouno` (ADR 0133). Xcode las copia al crear el target si ya existen en el
   proyecto; revisar que estén.
5. *Build Settings* del target `ProximoWidget`, en cada configuración:
   - `PRODUCT_BUNDLE_IDENTIFIER`: `com.agendauno.app.ProximoWidget` (las
     `-agendauno`) o `com.turnouno.app.ProximoWidget` (las `-turnouno`).
   - Nuevo *User-Defined Setting* `APP_GROUP_ID`: `group.com.agendauno.app` o
     `group.com.turnouno.app` (el mismo que `Runner` en esa configuración).
   - Nuevo *User-Defined Setting* `FLAVOR`: `agendauno` o `turnouno` (como `Runner`).
   - `IPHONEOS_DEPLOYMENT_TARGET`: 15.0, como `Runner` (desde iOS 17 usa
     `containerBackground`; antes, el fondo del sistema).
   - `TARGETED_DEVICE_FAMILY`: `1` (solo iPhone), como `Runner`.
6. En el target `Runner`, revisar que cada configuración tenga su `APP_GROUP_ID` (ya
   viene en el proyecto).
7. *Signing & Capabilities*: agregar **App Groups** en `Runner` y en
   `ProximoWidget`, con `$(APP_GROUP_ID)`.
   - En los `.entitlements` queda así:
     `com.apple.security.application-groups` → `$(APP_GROUP_ID)`.
   - `Runner/Runner.entitlements` ya tiene `aps-environment`: se conserva.
8. En el `Info.plist` de `ProximoWidget`:
   - la llave `AppGroupId` (String) con `$(APP_GROUP_ID)`: el widget la lee para abrir
     el mismo UserDefaults que la app;
   - la llave `AppProducto` (String) con `$(FLAVOR)`: con `turnouno`, el widget dice
     «cita» y no «reserva» ni «clase».
9. *Build Phases* de `Runner`: si al compilar sale «Cycle inside Runner», mover
   *Embed Foundation Extensions* arriba de *Run Script* (*Thin Binary*).
10. En el portal de Apple Developer:
    - Registrar los App Groups `group.com.agendauno.app` y `group.com.turnouno.app`.
    - Activarlos en los App IDs `com.agendauno.app`, `com.turnouno.app` y los de sus
      extensiones (`….ProximoWidget`).
    - Regenerar los perfiles de aprovisionamiento.

## Probarlo

```bash
flutter run --flavor agendauno   # y --flavor turnouno
```

1. Entrar como cliente y abrir Mi cuenta.
2. Agregar el widget «Mi próxima reserva» («Mi próxima cita» en TurnoUno) a la
   pantalla de inicio.
3. Reservar o cancelar en la app: el widget cambia al momento.
4. Con una clase ya terminada, el widget pasa solo a la siguiente.
5. Al cerrar sesión, el widget pide abrir la app.

## Notas

- Si el App Group no está configurado, la app sigue igual: home_widget falla en
  silencio y el widget no muestra datos.
- La marca blanca no tiene iOS por ahora (MOBILE.md, «Marca blanca en iOS»): su
  compilación se detiene antes de compilar. Cuando lo tenga, llevará su propio
  `APP_GROUP_ID` (el `--dart-define` y el de sus configuraciones de Xcode).
- Con la app cerrada, un push no actualiza el widget: eso pediría leer la cuenta en
  segundo plano. Se actualiza en cuanto se abre la app.
- El widget solo muestra lo que el cliente ya ve en su Inicio: la clase o el servicio,
  la hora, la sede y el saldo. Nunca el profesional ni la persona a su cargo.
