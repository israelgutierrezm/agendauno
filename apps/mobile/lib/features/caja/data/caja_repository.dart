import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/intento.dart';
import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import 'caja_models.dart';

/// La caja de cada sucursal desde la app del equipo (ADR 0175), con las mismas
/// rutas que la web (permiso `caja.operar`): ver la de una sede, abrirla con su
/// fondo, registrar retiros e ingresos de efectivo, contarla a ciegas y, si no
/// cuadró, cerrarla con el motivo. Los errores del API (caja ya abierta, ya
/// contada, datos inválidos) salen como [DioException]: la pantalla dice por qué.
class CajaRepository {
  CajaRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  /// Un retiro o ingreso por intento (clave del API): repetir el mismo tras
  /// perder la respuesta no lo registra dos veces.
  final _intentoMovimiento = IntentoUnico();

  String get _base => '/api/v1/app/$_slug';

  Map<String, dynamic> _data(Response<Map<String, dynamic>> res) =>
      (res.data?['data'] ?? const <String, dynamic>{}) as Map<String, dynamic>;

  /// La caja de la sede: su turno sin cerrar (o ninguno) y si ya usa caja.
  Future<CajaSede> actual(String sucursalId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/caja',
      queryParameters: {'sucursal_id': sucursalId},
    );
    return CajaSede.desdeJson(_data(res));
  }

  /// Abre la caja de la sede con su fondo (en centavos). 409 si ya hay una sin
  /// cerrar en esa sede.
  Future<TurnoCaja> abrir(
    String sucursalId, {
    required int fondoMinor,
    String? nota,
  }) async {
    final limpia = nota?.trim();
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/caja/turnos',
      data: {
        'sucursal_id': sucursalId,
        'fondo_minor': fondoMinor,
        'nota': limpia == null || limpia.isEmpty ? null : limpia,
      },
    );
    return TurnoCaja.desdeJson(_data(res));
  }

  /// Un retiro (`retiro`) o un ingreso (`ingreso`) de efectivo que no es venta,
  /// con su motivo. Con la misma clave mientras se repite lo mismo; tras una
  /// respuesta del servidor (registrado o rechazado), lo siguiente es otro intento.
  /// Sin respuesta (sin red), la clave se conserva para reintentar.
  Future<TurnoCaja> moverEfectivo(
    String turnoId, {
    required String tipo,
    required int montoMinor,
    required String motivo,
  }) async {
    final cuerpo = {
      'tipo': tipo,
      'monto_minor': montoMinor,
      'motivo': motivo.trim(),
    };
    try {
      final res = await _dio.post<Map<String, dynamic>>(
        '$_base/caja/turnos/$turnoId/movimientos',
        data: {...cuerpo, 'clave': _intentoMovimiento.clave(cuerpo)},
      );
      _intentoMovimiento.listo();
      return TurnoCaja.desdeJson(_data(res));
    } on DioException catch (e) {
      final estado = e.response?.statusCode;
      if (estado != null && estado < 500) {
        _intentoMovimiento.listo();
      }
      rethrow;
    }
  }

  /// El conteo a ciegas, una sola vez: lo contado de cada forma de pago (en
  /// centavos; lo que falte cuenta cero). Si cuadra, la caja queda cerrada; si no,
  /// «contada», con la diferencia de cada forma.
  Future<ResultadoConteo> contar(
    String turnoId,
    Map<String, int> contadoMinor,
  ) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/caja/turnos/$turnoId/conteo',
      data: {
        for (final m in MetodosCaja.todos) '${m}_minor': contadoMinor[m] ?? 0,
      },
    );
    final data = _data(res);
    return ResultadoConteo(
      turno: TurnoCaja.desdeJson(data),
      cuadra: data['cuadra'] == true,
    );
  }

  /// Cierra la caja contada que no cuadró, con el motivo (queda para revisión).
  Future<TurnoCaja> cerrar(String turnoId, String motivo) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/caja/turnos/$turnoId/cierre',
      data: {'motivo': motivo.trim()},
    );
    return TurnoCaja.desdeJson(_data(res));
  }
}

/// ¿Puede operar la caja? Con el permiso de sus rutas (como la web).
bool puedeOperarCaja(Sesion? sesion) => sesion?.puede('caja.operar') ?? false;

/// Repositorio ligado a la sesión activa (null si no hay sesión).
final cajaRepositoryProvider = Provider<CajaRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return CajaRepository(ref.watch(dioProvider), sesion.slug);
});
