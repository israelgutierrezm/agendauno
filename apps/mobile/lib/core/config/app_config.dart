import 'package:flutter/foundation.dart' show kReleaseMode;

import '../version/version_app.dart';
import 'producto_app.dart';

/// Static application configuration.
class AppConfig {
  const AppConfig._();

  /// El producto de esta compilación (AgendaUno o TurnoUno, ADR 0111).
  static const ProductoApp producto = ProductoApp.actual;

  /// Base URL of the API.
  ///
  /// Web and the iOS simulator reach the host at `localhost`; the Android
  /// emulator reaches the host machine at `10.0.2.2`. Override at run time with:
  ///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
  /// Una compilación de release sin `API_BASE_URL` apunta a la producción de su
  /// producto (`agendauno.mx` o `turnouno.mx`; nunca a localhost por olvido).
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: kReleaseMode
        ? (ProductoApp.esTurnoUno
              ? 'https://turnouno.mx'
              : 'https://agendauno.mx')
        : 'http://localhost:8000',
  );

  /// La API con la que habla la app del otro producto: con `API_BASE_URL` (desarrollo,
  /// pruebas) la misma; en producción, la de su dominio.
  static String apiBaseUrlDe(ProductoApp otro) =>
      const bool.hasEnvironment('API_BASE_URL') || !kReleaseMode
      ? apiBaseUrl
      : otro.urlProduccion;

  /// Base URL del sitio web (sirve las fotos de cada giro que usa el Inicio). En
  /// producción la web y la API comparten dominio; en desarrollo la web va aparte:
  ///   flutter run --dart-define=WEB_BASE_URL=http://localhost:5175
  static const String webBaseUrl = String.fromEnvironment(
    'WEB_BASE_URL',
    defaultValue: apiBaseUrl,
  );

  /// Versión de la app que viaja en sus errores (ADR 0080); por omisión, la de
  /// pubspec.yaml (`versionApp`):
  ///   flutter build appbundle --dart-define=APP_VERSION=1.4.0
  static const String version = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: versionApp,
  );

  /// Negocio fijo de una app de marca blanca (ADR 0111; solo AgendaUno): abre
  /// directo en él, sin pedir la dirección del negocio, y la API no la deja abrir
  /// otro. Vacío en las apps oficiales. Se compila con su archivo de configuración:
  ///   flutter build appbundle --flavor agendauno \
  ///     --dart-define-from-file=configuraciones/marca_blanca/NEGOCIO.json
  static const String negocioFijo = ProductoApp.esTurnoUno
      ? ''
      : String.fromEnvironment('NEGOCIO');

  /// ¿Es una app de marca blanca?
  static const bool marcaBlanca = negocioFijo != '';

  /// El nombre de la app (título, inicio de sesión, avisos): el de la marca blanca
  /// o el del producto.
  static const String nombreApp = marcaBlanca
      ? (_nombreDefinido != '' ? _nombreDefinido : negocioFijo)
      : (ProductoApp.esTurnoUno ? 'TurnoUno' : 'AgendaUno');
  static const String _nombreDefinido = String.fromEnvironment('APP_NOMBRE');

  /// Id de la app en Google Play: el `applicationId` de su sabor en
  /// android/app/build.gradle.kts (el de la marca blanca, `ANDROID_ID`).
  static const String idAndroid = marcaBlanca
      ? String.fromEnvironment('ANDROID_ID')
      : (ProductoApp.esTurnoUno
            ? ProductoApp.idAndroidTurnoUno
            : ProductoApp.idAndroidAgendaUno);

  /// El esquema propio de esta app para volver a ella desde el navegador (el regreso
  /// del pago: `<esquema>://pagos?ref=…`): `agendauno`, `turnouno` o, en una marca
  /// blanca, su `ANDROID_ID` en minúsculas con «-» en lugar de «_». Es el mismo que
  /// declaran Android (`esquemaApp` en build.gradle.kts) e iOS (`APP_URL_SCHEME`).
  static String get esquemaApp => marcaBlanca
      ? idAndroid.toLowerCase().replaceAll('_', '-')
      : (ProductoApp.esTurnoUno ? 'turnouno' : 'agendauno');

  /// Id numérico de la app en el App Store. Se pasa al compilar para iOS; sin él,
  /// la pantalla de actualizar no ofrece abrir la tienda:
  ///   flutter build ipa --dart-define=APP_STORE_ID=1234567890
  static const String appStoreId = String.fromEnvironment('APP_STORE_ID');

  /// El App Group de iOS que la app comparte con su widget de inicio
  /// (ios/WIDGET.md): por omisión `group.` + el id de la app oficial; una app con
  /// otro id (marca blanca, cuando tenga iOS) trae el suyo en `APP_GROUP_ID`.
  static const String appGroupId = _grupoDefinido != ''
      ? _grupoDefinido
      : (ProductoApp.esTurnoUno
            ? 'group.${ProductoApp.idAndroidTurnoUno}'
            : 'group.${ProductoApp.idAndroidAgendaUno}');
  static const String _grupoDefinido = String.fromEnvironment('APP_GROUP_ID');

  /// El color de la marca blanca (`#RRGGBB`, opcional en su archivo): el del ícono
  /// adaptativo de Android y el del tema mientras no hay sesión; con sesión manda
  /// el `color_marca` del negocio. Vacío en las apps oficiales.
  static const String colorMarca = marcaBlanca
      ? String.fromEnvironment('COLOR_MARCA')
      : '';

  /// El dominio de la web de esta app (su aviso de privacidad, sus términos y el
  /// aviso de cada negocio): la de `WEB_BASE_URL` o, por omisión, la de su API.
  static Uri urlWeb(String ruta) =>
      Uri.parse(webBaseUrl.replaceFirst(RegExp(r'/+$'), '') + ruta);

  /// ¿La petición va a la API de esta app (mismo esquema, host y puerto)? Solo a
  /// ella viajan el token de la sesión y la persona a cargo: no a la API del otro
  /// producto (`enOtroProducto`) ni a ningún otro sitio.
  static bool esDeLaApi(Uri uri) {
    final api = Uri.parse(apiBaseUrl);
    return uri.scheme == api.scheme &&
        uri.host.toLowerCase() == api.host.toLowerCase() &&
        uri.port == api.port;
  }
}
