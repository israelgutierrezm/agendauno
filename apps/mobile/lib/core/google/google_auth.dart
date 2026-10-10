import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_sign_in/google_sign_in.dart';

/// Clientes OAuth de Google de la app (ADR 0093). Se pasan al compilar:
///
///   flutter run --dart-define=GOOGLE_SERVER_CLIENT_ID=CLIENTE_WEB \
///     [--dart-define=GOOGLE_IOS_CLIENT_ID=CLIENTE_IOS]
///
/// El cliente web es el mismo que `GOOGLE_CLIENT_ID` del API: en Android hace que
/// el ID token salga para él. En iOS haría falta además el cliente de iOS (y su URL
/// scheme en Info.plist), pero por ahora iOS no ofrece Google (ver `disponible`).
/// Los IDs de Android e iOS se agregan a `GOOGLE_CLIENT_IDS_APP` del API. Sin el
/// cliente web, la app no ofrece Google.
class GoogleConfig {
  const GoogleConfig._();

  static const String serverClientId = String.fromEnvironment(
    'GOOGLE_SERVER_CLIENT_ID',
  );
  static const String iosClientId = String.fromEnvironment(
    'GOOGLE_IOS_CLIENT_ID',
  );

  static bool get configurado => serverClientId.isNotEmpty;
}

/// Pide a Google un ID token para entrar o para conectar la cuenta. Google no
/// crea cuentas: el servidor solo acepta cuentas que ya lo conectaron (ADR 0093).
abstract class GoogleAuth {
  /// ¿Se puede usar Google en esta app (compilada con sus clientes)?
  bool get disponible;

  /// El ID token de la cuenta elegida, o null si la persona canceló.
  Future<String?> idToken();
}

class GoogleAuthNativo implements GoogleAuth {
  bool _iniciado = false;

  // En iOS no se ofrece en la v1: Info.plist no trae el URL scheme del cliente de
  // iOS (CFBundleURLTypes con el id invertido), sin el cual el SDK nativo truena, y
  // ofrecer Google sin «Iniciar sesión con Apple» choca con la regla 4.8 del App
  // Store. Ahí se entra con correo y contraseña.
  @override
  bool get disponible =>
      GoogleConfig.configurado &&
      !kIsWeb &&
      defaultTargetPlatform != TargetPlatform.iOS;

  @override
  Future<String?> idToken() async {
    final google = GoogleSignIn.instance;
    if (!_iniciado) {
      await google.initialize(
        clientId: GoogleConfig.iosClientId.isEmpty
            ? null
            : GoogleConfig.iosClientId,
        serverClientId: GoogleConfig.serverClientId,
      );
      _iniciado = true;
    }
    try {
      final cuenta = await google.authenticate();
      final token = cuenta.authentication.idToken;
      // El token ya se usó: no se deja abierta la sesión de Google en la app.
      await google.signOut();
      return token;
    } on GoogleSignInException catch (e) {
      if (e.code == GoogleSignInExceptionCode.canceled) {
        return null;
      }
      rethrow;
    }
  }
}

final googleAuthProvider = Provider<GoogleAuth>((ref) => GoogleAuthNativo());
