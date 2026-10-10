import 'package:flutter/foundation.dart' show kReleaseMode;

import '../version/version_app.dart';

/// Static application configuration.
class AppConfig {
  const AppConfig._();

  /// Base URL of the AgendaUno API.
  ///
  /// Web and the iOS simulator reach the host at `localhost`; the Android
  /// emulator reaches the host machine at `10.0.2.2`. Override at run time with:
  ///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000
  /// Una compilación de release sin `API_BASE_URL` apunta a producción (nunca a
  /// localhost por olvido).
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: kReleaseMode
        ? 'https://agendauno.mx'
        : 'http://localhost:8000',
  );

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

  /// Id de la app en Google Play: el `applicationId` de
  /// android/app/build.gradle.kts (si cambia allá, cambia aquí).
  static const String idAndroid = 'com.agendauno.app';

  /// Id numérico de la app en el App Store. Se pasa al compilar para iOS; sin él,
  /// la pantalla de actualizar no ofrece abrir la tienda:
  ///   flutter build ipa --dart-define=APP_STORE_ID=1234567890
  static const String appStoreId = String.fromEnvironment('APP_STORE_ID');
}
