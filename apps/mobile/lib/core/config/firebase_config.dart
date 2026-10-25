import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';

/// Proyecto de Firebase de la app (notificaciones push). Los datos se pasan al
/// compilar (Configuración del proyecto en la consola de Firebase). La app de Android
/// y la de iOS son dos apps del proyecto, con su propio id (y a veces su propia
/// llave): cada una lleva las suyas y la app elige las de la plataforma en la que
/// corre, así un mismo archivo de configuración sirve para las dos:
///
///   flutter run --dart-define=FIREBASE_SENDER_ID=... \
///     --dart-define=FIREBASE_PROJECT_ID=... \
///     --dart-define=FIREBASE_ANDROID_APP_ID=... --dart-define=FIREBASE_IOS_APP_ID=... \
///     --dart-define=FIREBASE_API_KEY=... [--dart-define=FIREBASE_IOS_API_KEY=...] \
///     [--dart-define=FIREBASE_IOS_BUNDLE_ID=...]
///
/// `FIREBASE_APP_ID` (y `FIREBASE_API_KEY`) siguen valiendo para la plataforma que
/// no tenga los suyos: así se compilaba antes, una vez por plataforma.
///
/// Sin ellos la app funciona igual, solo que sin notificaciones push (una marca
/// blanca de release no compila sin los suyos: android/app/build.gradle.kts).
class FirebaseConfig {
  const FirebaseConfig._();

  static const String _apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const String _apiKeyAndroid = String.fromEnvironment(
    'FIREBASE_ANDROID_API_KEY',
  );
  static const String _apiKeyIos = String.fromEnvironment(
    'FIREBASE_IOS_API_KEY',
  );
  static const String _appId = String.fromEnvironment('FIREBASE_APP_ID');
  static const String _appIdAndroid = String.fromEnvironment(
    'FIREBASE_ANDROID_APP_ID',
  );
  static const String _appIdIos = String.fromEnvironment('FIREBASE_IOS_APP_ID');
  static const String _senderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const String _proyecto = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const String _bundleIos = String.fromEnvironment(
    'FIREBASE_IOS_BUNDLE_ID',
  );

  /// Las opciones del proyecto para la plataforma en la que corre la app, o null
  /// si se compiló sin ellas.
  static FirebaseOptions? get opciones =>
      opcionesPara(defaultTargetPlatform, valores: _compilados);

  static const _compilados = ValoresFirebase(
    apiKey: _apiKey,
    apiKeyAndroid: _apiKeyAndroid,
    apiKeyIos: _apiKeyIos,
    appId: _appId,
    appIdAndroid: _appIdAndroid,
    appIdIos: _appIdIos,
    senderId: _senderId,
    proyecto: _proyecto,
    bundleIos: _bundleIos,
  );

  /// Las de esa plataforma: su id y su llave o, si no los trae, los generales.
  static FirebaseOptions? opcionesPara(
    TargetPlatform plataforma, {
    required ValoresFirebase valores,
  }) {
    final esIos = plataforma == TargetPlatform.iOS;
    String propio(String deIos, String deAndroid, String general) {
      final suyo = esIos ? deIos : deAndroid;
      return suyo.isNotEmpty ? suyo : general;
    }

    final appId = propio(valores.appIdIos, valores.appIdAndroid, valores.appId);
    final apiKey = propio(
      valores.apiKeyIos,
      valores.apiKeyAndroid,
      valores.apiKey,
    );
    if ([apiKey, appId, valores.senderId, valores.proyecto].any(
      (v) => v.isEmpty,
    )) {
      return null;
    }
    return FirebaseOptions(
      apiKey: apiKey,
      appId: appId,
      messagingSenderId: valores.senderId,
      projectId: valores.proyecto,
      iosBundleId: esIos && valores.bundleIos.isNotEmpty
          ? valores.bundleIos
          : null,
    );
  }
}

/// Los datos de Firebase con que se compiló la app (o los de una prueba).
class ValoresFirebase {
  const ValoresFirebase({
    this.apiKey = '',
    this.apiKeyAndroid = '',
    this.apiKeyIos = '',
    this.appId = '',
    this.appIdAndroid = '',
    this.appIdIos = '',
    this.senderId = '',
    this.proyecto = '',
    this.bundleIos = '',
  });

  final String apiKey;
  final String apiKeyAndroid;
  final String apiKeyIos;
  final String appId;
  final String appIdAndroid;
  final String appIdIos;
  final String senderId;
  final String proyecto;
  final String bundleIos;
}
