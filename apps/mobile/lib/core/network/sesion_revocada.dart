import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Rutas del negocio que no dependen de una sesión: un 401 ahí no quiere decir
/// que la sesión terminó (entrar, entrar con Google, salir o recuperar acceso).
const _rutasSinSesion = {
  'login',
  'auth/google',
  'logout',
  'recuperar-contrasena',
};

final _rutaDelNegocio = RegExp(r'/api/v1/app/[^/]+/(.+)$');

/// ¿El servidor ya no reconoce el token con el que se hizo esta petición? Solo un
/// 401 de una ruta del negocio (`/api/v1/app/{slug}/…`) que no sea de entrar o
/// salir, y solo si la petición llevó el token vigente: la respuesta tardía de una
/// sesión anterior no cierra la nueva.
bool esSesionRevocada(DioException error, String? tokenVigente) {
  if (error.response?.statusCode != 401 ||
      tokenVigente == null ||
      tokenVigente.isEmpty) {
    return false;
  }
  final opciones = error.requestOptions;
  if (opciones.headers['Authorization'] != 'Bearer $tokenVigente') {
    return false;
  }
  final ruta = _rutaDelNegocio.firstMatch(opciones.uri.path)?.group(1);

  return ruta != null && !_rutasSinSesion.contains(ruta);
}

/// Aviso de «el servidor terminó la sesión» (se cerró sesión en otro lado, se
/// cambió la contraseña o se dio de baja la cuenta). La red vive en `core` y no
/// conoce la sesión: la sesión se registra aquí al crearse y el interceptor de Dio
/// solo avisa (así no hay dependencia circular entre la red y la sesión).
class AvisoSesionRevocada {
  void Function()? _alRevocar;

  void escuchar(void Function()? alRevocar) => _alRevocar = alRevocar;

  void avisar() => _alRevocar?.call();
}

final avisoSesionRevocadaProvider = Provider<AvisoSesionRevocada>(
  (ref) => AvisoSesionRevocada(),
);
