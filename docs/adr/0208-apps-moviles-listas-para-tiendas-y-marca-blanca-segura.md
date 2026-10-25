# ADR 0208 — Apps móviles listas para las tiendas y marca blanca que no se equivoca

Estado: Aceptado (2026-10-24). Precisa el ADR 0111 (dos apps oficiales y marca blanca)
y el 0133 (sabores de iOS).

## Contexto

Una revisión de la app de Flutter antes de publicarla encontró:

- **iOS:** TurnoUno salía con el ícono de AgendaUno; no había manifiesto de privacidad
  (`PrivacyInfo.xcprivacy`, que Apple exige); la app se declaraba también para iPad sin
  haberse diseñado ni probado ahí; archivar desde Xcode podía compilar el Dart del otro
  producto (el `FLAVOR` de `Generated.xcconfig` es el de la última compilación por la
  línea de comandos); la pantalla de arranque destellaba en blanco en modo oscuro.
- **Privacidad:** la app no enlazaba el aviso de privacidad ni los términos.
- **Red:** el interceptor ponía el token de la sesión en cualquier URL, también al
  consultar la API del otro producto (`enOtroProducto`).
- **Vocabulario:** en TurnoUno (solo citas) aún se leía «reserva» o «clase» en
  cancelar, cambiar de horario, el Inicio y el widget; la terminología por omisión era
  la de clases.
- **Marca blanca:** en iOS, `--dart-define-from-file` de un negocio salía con el id y el
  nombre de AgendaUno; en Android, un archivo incompleto (sin `NEGOCIO`, con un slug
  inválido, sin íconos, sin Firebase) compilaba igual una app publicable con la marca
  de AgendaUno; Firebase pedía compilar una vez por plataforma con otras claves.

## Decisión

### Apps oficiales

- **iOS por sabor:** `AppIcon-TurnoUno` (provisional, hecho del ícono de Android de
  TurnoUno), `FLAVOR`, `APP_GROUP_ID` y `TARGETED_DEVICE_FAMILY = 1` (solo iPhone) en
  cada configuración del target `Runner`. `PrivacyInfo.xcprivacy` en `Runner`: no
  rastrea, los datos que recolecta para funcionar y `UserDefaults` (CA92.1 y 1C8F.1).
  La pantalla de arranque usa `systemBackground`.
- **Avisos legales en la app:** el acceso, Mi perfil y la cuenta del cliente enlazan
  `/aviso-de-privacidad` y `/terminos` de la web del producto; el cliente, también el
  aviso de su negocio (`/estudio/<slug>/aviso-de-privacidad`).
- **La sesión solo viaja a su API:** el bearer y `X-Participante` se ponen solo si la
  petición va al mismo esquema, host y puerto que `API_BASE_URL`.
- **Cada producto con su vocabulario:** lo que es de una cita se dice «cita» (con
  `sesion.esCitas` o la terminología del negocio). Sin terminología del servidor, la
  sesión toma la de su modalidad y, sin sesión, la de la app (Cita / Cliente /
  Profesional en TurnoUno). El widget de iOS lee el producto de su Info.plist.
- **iOS en el CI, a mano:** «Apps móviles» con plataforma `ios` compila sin firmar en
  macOS y revisa id, nombre, ícono, solo iPhone y el manifiesto. No firma ni sube nada.

### Marca blanca

- **iOS no soportado, y se detiene:** una fase de `Runner` lee `DART_DEFINES` y, con
  `NEGOCIO`, falla con un mensaje claro antes de compilar.
- **Android valida su archivo** (`build.gradle.kts`): `NEGOCIO` con forma de slug y
  presente si vienen `ANDROID_ID`, `APP_NOMBRE` o `COLOR_MARCA`; solo el sabor y el
  `PRODUCTO` de AgendaUno; `ANDROID_ID` propio; `APP_NOMBRE` no vacío; y en release, sus
  íconos completos y su app de Firebase. En debug basta el archivo.
- **Firebase por plataforma:** `FIREBASE_ANDROID_APP_ID` / `FIREBASE_IOS_APP_ID` (y sus
  llaves si difieren), elegidas al correr; `FIREBASE_APP_ID` / `FIREBASE_API_KEY` siguen
  valiendo para la que no traiga las suyas.
- **Su color:** el tema usa el `color_marca` del negocio que ya manda la sesión (o
  `COLOR_MARCA` antes de entrar), ajustado para leerse (4.5:1). `COLOR_MARCA` también
  puede ser el fondo de su ícono adaptativo (un recurso generado).
- **Su acceso:** si su negocio no está, «Esta app no está disponible por ahora.
  Contacta a <nombre>.», no «Revisa la dirección».
- **CI:** compila la de ejemplo, comprueba su id y su nombre, que con el sabor de
  TurnoUno Gradle se niega y corre `marca_blanca_test.dart` con su archivo.

## Consecuencias

- Antes de subir `app.version_minima` hay que publicar la versión nueva de cada marca
  blanca: la versión mínima es una para todas las apps.
- La «Privacidad de la app» de App Store Connect y la «Seguridad de los datos» de Play
  deben coincidir con `PrivacyInfo.xcprivacy`.
- Un archivo de marca blanca ya no lleva claves de iOS mientras no haya iOS de marca
  blanca (docs/MOBILE.md dice qué haría falta).

## Pendiente (decisiones del dueño, fuera de este ADR)

- Venta de videos o clases en línea dentro de la app de iOS (regla 3.1.1 de Apple).
- Eliminar la cuenta desde la app (Apple lo pide a las apps que crean cuentas; aquí las
  crea el negocio).
- Enlaces profundos y el widget de iOS como target (ios/WIDGET.md).
