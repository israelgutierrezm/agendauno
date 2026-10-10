import 'package:dio/dio.dart';

/// Lo que se le dice a la persona cuando una petición falla. El API responde
/// todo 422 con el mismo `message` ("Los datos proporcionados no son válidos.") y
/// la razón real en `meta.errors` (campo → lista de textos); además manda un
/// `code` estable (p. ej. PLAN_FEATURE_NOT_INCLUDED). En orden:
///
/// 1. el primer texto de `meta.errors`;
/// 2. 429: que espere un minuto;
/// 3. sin respuesta (sin red, tiempo agotado): que revise su conexión;
/// 4. una función que el plan del negocio no incluye: que no está disponible;
/// 5. el `message` del servidor;
/// 6. [porDefecto].
String mensajeDeError(
  Object error, {
  String porDefecto = 'No se pudo completar la acción.',
}) {
  if (error is! DioException) {
    return porDefecto;
  }
  final respuesta = error.response;
  final data = respuesta?.data;
  final cuerpo = data is Map ? data : null;

  final meta = cuerpo?['meta'];
  final errores = meta is Map ? meta['errors'] : null;
  if (errores is Map) {
    for (final textos in errores.values) {
      final texto = textos is List
          ? textos
                .whereType<String>()
                .where((t) => t.trim().isNotEmpty)
                .firstOrNull
          : (textos is String && textos.trim().isNotEmpty ? textos : null);
      if (texto != null) {
        return texto;
      }
    }
  }
  if (respuesta?.statusCode == 429) {
    return 'Demasiados intentos. Espera un minuto y vuelve a intentar.';
  }
  if (respuesta == null) {
    return 'Sin conexión. Revisa tu internet y vuelve a intentar.';
  }
  if (cuerpo?['code'] == 'PLAN_FEATURE_NOT_INCLUDED') {
    return 'Esta opción no está disponible en este negocio.';
  }
  final mensaje = cuerpo?['message'];
  if (mensaje is String && mensaje.trim().isNotEmpty) {
    return mensaje;
  }

  return porDefecto;
}
