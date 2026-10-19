import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/data/cuenta_models.dart';
import 'resumen_hoy.dart';
import 'resumen_mes.dart';

/// Datos del Inicio del negocio (`/app/{slug}/inicio/hoy` y `/clima`) sobre el
/// estudio de la sesión activa.
class InicioRepository {
  InicioRepository(this._dio, this._slug, [this._moneda = 'MXN']);

  final Dio _dio;
  final String _slug;

  /// Moneda del negocio: lo por cobrar se suma solo en ella y es el respaldo de los
  /// ingresos del mes.
  final String _moneda;

  String get _base => '/api/v1/app/$_slug';

  /// Un día del negocio ([dia]); sin él, el servidor da el de hoy en su zona.
  Future<ResumenHoy> hoy([DateTime? dia]) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/inicio/hoy',
      queryParameters: {if (dia != null) 'fecha': Formato.iso(dia)},
    );
    return ResumenHoy.desdeJson(
      (res.data?['data'] ?? const {}) as Map<String, dynamic>,
      moneda: _moneda,
    );
  }

  /// El mes en curso, del día 1 a [hoy] (días del negocio): el resumen del negocio
  /// y la agenda del equipo.
  Future<ResumenMes> mes(DateTime hoy, {required bool esCitas}) async {
    final periodo = {
      'desde': Formato.iso(DateTime(hoy.year, hoy.month)),
      'hasta': Formato.iso(hoy),
    };
    final respuestas = await Future.wait([
      _dio.get<Map<String, dynamic>>(
        '$_base/reportes/negocio',
        queryParameters: periodo,
      ),
      _dio.get<Map<String, dynamic>>(
        '$_base/reportes/equipo',
        queryParameters: periodo,
      ),
    ]);
    Map<String, dynamic> datos(Response<Map<String, dynamic>> r) =>
        (r.data?['data'] ?? const <String, dynamic>{}) as Map<String, dynamic>;
    return ResumenMes.desdeJson(
      datos(respuestas[0]),
      datos(respuestas[1]),
      esCitas: esCitas,
      moneda: _moneda,
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
  return InicioRepository(ref.watch(dioProvider), sesion.slug, sesion.moneda);
});

/// El día de hoy del negocio (con el desfase de su zona, no el día del teléfono).
/// Sin el desfase (API anterior) no se manda el día: el servidor da el de hoy en
/// su zona. Como todo lo de una sesión, se descarta al salir.
final resumenHoyProvider = FutureProvider.autoDispose<ResumenHoy?>((ref) async {
  final repo = ref.watch(inicioRepositoryProvider);
  final sesion = ref.watch(sesionProvider);
  final hoy = sesion?.desfaseNegocioMin == null
      ? null
      : sesion!.hoyEnNegocio(DateTime.now());
  return repo?.hoy(hoy);
});

/// El mes en curso del negocio (hasta hoy allá); solo para quien ve los números del
/// negocio (null si no).
final resumenMesProvider = FutureProvider.autoDispose<ResumenMes?>((ref) async {
  final sesion = ref.watch(sesionProvider);
  final repo = ref.watch(inicioRepositoryProvider);
  if (sesion == null || repo == null || !sesion.puede('facturacion.ver')) {
    return null;
  }
  return repo.mes(sesion.hoyEnNegocio(DateTime.now()), esCitas: sesion.esCitas);
});

/// El clima del Inicio del negocio; se vuelve a pedir con el resumen del día.
final climaNegocioProvider = FutureProvider.autoDispose<ClimaMiembro?>((
  ref,
) async {
  await ref.watch(resumenHoyProvider.future);
  final repo = ref.watch(inicioRepositoryProvider);
  return repo?.clima();
});
