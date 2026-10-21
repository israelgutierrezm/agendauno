import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../../core/network/mensaje_error.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import 'lectura_pase.dart';

/// La entrada del negocio desde la app del equipo (R12): registra la llegada con el
/// pase QR del cliente (`POST /accesos`, permiso `checkins.registrar`), como la
/// recepción de la web. El servidor resuelve el pase y decide si pasa.
class AccesosRepository {
  AccesosRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  String get _base => '/api/v1/app/$_slug';

  /// Registra la entrada con [codigo] (lo que leyó la cámara) en la sede
  /// [sucursalId] (sin ella, en cualquiera). Nunca lanza: lo que no se pudo
  /// registrar vuelve como [PaseNoRegistrado] con su razón. Un código que no es un
  /// pase ni se manda.
  Future<LecturaPase> registrarPase(String codigo, {String? sucursalId}) async {
    final pase = codigo.trim();
    if (!esPaseDeEntrada(pase)) {
      return const PaseNoRegistrado('Ese código no es un pase de entrada.');
    }
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '$_base/accesos',
        data: {'codigo': pase, 'sucursal_id': ?sucursalId},
      );
      return EntradaRegistrada.desdeJson(
        (res.data?['data'] ?? const <String, dynamic>{})
            as Map<String, dynamic>,
      );
    } on DioException catch (e) {
      // Vencido, de otro negocio, ya sin efecto, sin permiso o sin conexión.
      return PaseNoRegistrado(
        mensajeDeError(e, porDefecto: 'No se pudo registrar la entrada.'),
      );
    }
  }
}

/// ¿Puede escanear pases? Solo en un negocio que controla la entrada (ahí el
/// cliente tiene pase, ADR 0105) y con el permiso del endpoint, como en la web.
bool puedeEscanearPase(Sesion? sesion) =>
    sesion != null &&
    sesion.accesoAbierto &&
    sesion.puede('checkins.registrar');

/// Repositorio ligado a la sesión activa (null si no hay sesión).
final accesosRepositoryProvider = Provider<AccesosRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return AccesosRepository(ref.watch(dioProvider), sesion.slug);
});
