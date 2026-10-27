import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import 'mis_comisiones.dart';

/// «Mis comisiones» de quien atiende (`GET /app/{slug}/mis-comisiones`, ADR 0219)
/// sobre el negocio de la sesión activa. Sin la función de comisiones en el plan
/// del negocio responde 403 (`PLAN_FEATURE_NOT_INCLUDED`).
class ComisionesRepository {
  ComisionesRepository(this._dio, this._slug, [this._moneda = 'MXN']);

  final Dio _dio;
  final String _slug;

  /// Moneda del negocio: la de un periodo sin filas.
  final String _moneda;

  String get _base => '/api/v1/app/$_slug';

  /// Del día [desde] al [hasta] (días del negocio, incluidos).
  Future<MisComisiones> delPeriodo(DateTime desde, DateTime hasta) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mis-comisiones',
      queryParameters: {
        'desde': Formato.iso(desde),
        'hasta': Formato.iso(hasta),
      },
    );
    return MisComisiones.desdeJson(res.data?['data'], moneda: _moneda);
  }
}

final comisionesRepositoryProvider = Provider<ComisionesRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return ComisionesRepository(
    ref.watch(dioProvider),
    sesion.slug,
    sesion.moneda,
  );
});
