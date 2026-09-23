import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Bearer token tenant-local vigente (o null). Lo escribe la sesion tras iniciar
/// sesion y lo lee el interceptor de Dio para autenticar cada peticion. Vive en
/// `core` para que la red no dependa de una feature.
class AuthToken extends Notifier<String?> {
  @override
  String? build() => ref.read(tokenInicialProvider);

  void establecer(String? token) => state = token;
}

final authTokenProvider = NotifierProvider<AuthToken, String?>(AuthToken.new);

/// Token de una sesión restaurada al abrir la app (lo fija `main`).
final tokenInicialProvider = Provider<String?>((ref) => null);
