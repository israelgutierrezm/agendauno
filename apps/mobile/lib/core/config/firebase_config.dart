import 'package:firebase_core/firebase_core.dart';

/// Proyecto de Firebase de la app (notificaciones push). Los datos se pasan al
/// compilar, por plataforma (Configuración del proyecto en la consola de Firebase):
///
///   flutter run --dart-define=FIREBASE_API_KEY=... \
///     --dart-define=FIREBASE_APP_ID=... --dart-define=FIREBASE_SENDER_ID=... \
///     --dart-define=FIREBASE_PROJECT_ID=... [--dart-define=FIREBASE_IOS_BUNDLE_ID=...]
///
/// Sin ellos la app funciona igual, solo que sin notificaciones push.
class FirebaseConfig {
  const FirebaseConfig._();

  static const String _apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const String _appId = String.fromEnvironment('FIREBASE_APP_ID');
  static const String _senderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const String _proyecto = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const String _bundleIos = String.fromEnvironment(
    'FIREBASE_IOS_BUNDLE_ID',
  );

  /// Las opciones del proyecto, o null si la app se compiló sin ellas.
  static FirebaseOptions? get opciones {
    if ([_apiKey, _appId, _senderId, _proyecto].any((v) => v.isEmpty)) {
      return null;
    }
    return FirebaseOptions(
      apiKey: _apiKey,
      appId: _appId,
      messagingSenderId: _senderId,
      projectId: _proyecto,
      iosBundleId: _bundleIos.isEmpty ? null : _bundleIos,
    );
  }
}
