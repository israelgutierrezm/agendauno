import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/data/cuenta_models.dart';
import 'resumen_hoy.dart';

/// Datos del Inicio del negocio (`/app/{slug}/inicio/hoy` y `/clima`) sobre el
/// estudio de la sesión activa.
class InicioRepository {
  InicioRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  String get _base => '/api/v1/app/$_slug';

  /// El día LOCAL del teléfono (el que ve quien atiende).
  Future<ResumenHoy> hoy(DateTime dia) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/inicio/hoy',
      queryParameters: {'fecha': Formato.iso(dia)},
    );
    return ResumenHoy.desdeJson(
      (res.data?['data'] ?? const {}) as Map<String, dynamic>,
    );
  }

  /// El clima del negocio (o el de la próxima clase de quien entra). Null si falla.
  Future<ClimaMiembro?> clima() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('$_base/clima');
      final data = res.data?['data'];
      return data is Map<String, dynamic> ? ClimaMiembro.desdeJson(data) : null;
    } on DioException {
      return null;
    }
  }
}

final inicioRepositoryProvider = Provider<InicioRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return InicioRepository(ref.watch(dioProvider), sesion.slug);
});

/// El día de hoy del negocio. Como todo lo de una sesión, se descarta al salir.
final resumenHoyProvider = FutureProvider.autoDispose<ResumenHoy?>((ref) async {
  final repo = ref.watch(inicioRepositoryProvider);
  return repo?.hoy(DateTime.now());
});

/// El clima del Inicio del negocio; se vuelve a pedir con el resumen del día.
final climaNegocioProvider = FutureProvider.autoDispose<ClimaMiembro?>((
  ref,
) async {
  await ref.watch(resumenHoyProvider.future);
  final repo = ref.watch(inicioRepositoryProvider);
  return repo?.clima();
});
