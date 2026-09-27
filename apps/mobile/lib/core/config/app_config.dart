/// Static application configuration.
class AppConfig {
  const AppConfig._();

  /// Base URL of the AgendaUno API.
  ///
  /// Web and the iOS simulator reach the host at `localhost`; the Android
  /// emulator reaches the host machine at `10.0.2.2`. Override at run time with:
  ///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://localhost:8000',
  );

  /// Base URL del sitio web (sirve las fotos de cada giro que usa el Inicio). En
  /// producción la web y la API comparten dominio; en desarrollo la web va aparte:
  ///   flutter run --dart-define=WEB_BASE_URL=http://localhost:5175
  static const String webBaseUrl = String.fromEnvironment(
    'WEB_BASE_URL',
    defaultValue: apiBaseUrl,
  );
}
