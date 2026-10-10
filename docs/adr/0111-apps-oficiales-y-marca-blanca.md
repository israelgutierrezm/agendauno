# ADR 0111 — Dos apps oficiales desde un código y la marca blanca de AgendaUno

Estado: Aceptado (2026-10-10). Parte del ADR 0108 (plataforma multiproducto).

## Contexto

Cada producto necesita su app en las tiendas: AgendaUno (negocios de clases) y TurnoUno
(negocios de citas), en Android y en iOS, como apps distintas (su id, su nombre y su
ícono). La app de Flutter actual ya atiende las dos modalidades (ADR 0104). No se debe
duplicar el código, ni crear un proyecto o una base de datos por app o por cliente.
AgendaUno debe quedar lista para apps de marca blanca (la app propia de un negocio),
sin publicarlas todavía; TurnoUno no tiene marca blanca.

## Decisión

- **Un proyecto, dos sabores.** `apps/mobile` compila las dos apps con sabores de
  Android (`productFlavors`, dimensión `producto`):

  | | AgendaUno | TurnoUno |
  |---|---|---|
  | Sabor | `agendauno` (por omisión: `default-flavor` en `pubspec.yaml`) | `turnouno` |
  | Android `applicationId` | `com.agendauno.app` | `com.turnouno.app` |
  | Nombre | AgendaUno | TurnoUno |
  | API en release | `https://agendauno.mx` | `https://turnouno.mx` |
  | Ícono | el isotipo de AgendaUno (`src/main`) | provisional, su inicial (`src/turnouno`) |

  En Dart, `ProductoApp.actual` sale del sabor (`appFlavor`) o de
  `--dart-define=PRODUCTO=turnouno` (para iOS mientras no tenga sus esquemas). Las
  pantallas, la red, los datos y las reglas son los mismos.
- **Cada petición dice de qué app viene.** Dio manda `X-App-Producto`; la API
  (`ResolverEstudio`) no abre en la app de TurnoUno un negocio de AgendaUno ni al
  revés, también fuera de los dominios (en desarrollo). En producción ya lo impedía el
  dominio (ADR 0108). Si el negocio es del otro producto, la app lo dice («Este negocio
  usa TurnoUno. Descarga la app de TurnoUno para entrar.») consultando el `/marca`
  público del otro producto. La web no manda estos encabezados: nada cambia para ella.
- **Marca blanca (solo AgendaUno, preparada, sin publicar).** Una app de marca blanca
  es el sabor `agendauno` compilado con el archivo de su negocio
  (`--dart-define-from-file=configuraciones/marca_blanca/<negocio>.json`): `NEGOCIO`
  (el slug), `APP_NOMBRE`, `ANDROID_ID` y sus datos de Firebase y del App Store. Abre
  directo en su negocio (el acceso solo pide las credenciales) y manda
  `X-App-Negocio`: la API solo le abre ese negocio y solo si es de AgendaUno. Sus
  íconos, si los tiene, van en `configuraciones/marca_blanca/<negocio>/android/res`.
  Gradle se niega a compilar una marca blanca con el sabor de TurnoUno o sin un
  `ANDROID_ID` propio (nunca con el id de una app oficial).
- **Push.** Un solo proyecto de Firebase con las apps registradas (las dos oficiales y,
  cuando existan, las de marca blanca); cada compilación lleva su `FIREBASE_APP_ID`.
  El servidor envía con la misma cuenta de servicio.
- **iOS** queda preparado en código (producto por `PRODUCTO`), pero los esquemas y
  configuraciones de Xcode (`turnouno`, su bundle id y su nombre) se crean en una Mac
  (docs/MOBILE.md); aquí solo se compila Android.

## Consecuencias

- Una corrección se publica en las dos apps con el mismo código; las tiendas ven dos
  apps independientes (fichas, versiones, reseñas).
- El encabezado de la app no es seguridad (cualquiera puede mandarlo): la seguridad
  sigue siendo el token de cada negocio. Evita errores de configuración y que una app
  de marca blanca sirva a otro negocio.
- La versión mínima aceptada (`app.version_minima`) es una para todas las apps.

## Pendiente

- Logotipo e íconos definitivos de TurnoUno (hoy, su inicial, como su PWA).
- Esquemas de iOS y publicación de las dos apps: requieren una Mac, las cuentas de
  desarrollador y autorización.
- Alta de una marca blanca desde el superadmin (qué negocios la tienen, su estado en
  las tiendas) y su precio: hoy se prepara a mano con su archivo.
