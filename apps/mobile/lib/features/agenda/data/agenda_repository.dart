import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/data/cuenta_models.dart';
import 'agenda_models.dart';

/// Agenda del staff (`/app/{slug}/…`): sesiones del día, profesionales y las
/// acciones de recepción sobre una cita (llegada, inasistencia, cobro, cancelación).
class AgendaRepository {
  AgendaRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  String get _base => '/api/v1/app/$_slug';

  static String ymd(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<List<SesionAgenda>> sesionesDelDia(DateTime dia) => sesiones(dia, dia);

  /// Sesiones entre dos días locales (ambos incluidos). A un instructor el
  /// servidor solo le da las suyas.
  Future<List<SesionAgenda>> sesiones(DateTime desde, DateTime hasta) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/sesiones',
      queryParameters: {'desde': ymd(desde), 'hasta': ymd(hasta)},
    );
    final (a, b) = (ymd(desde), ymd(hasta));
    return ((res.data?['data'] ?? []) as List)
        .map((e) => SesionAgenda.desdeJson(e as Map<String, dynamic>))
        // El servidor ensancha la ventana un día por lado (zonas): se filtran los días locales.
        .where((s) => ymd(s.iniciaEn).compareTo(a) >= 0 && ymd(s.iniciaEn).compareTo(b) <= 0)
        .toList()
      ..sort((x, y) => x.iniciaEn.compareTo(y.iniciaEn));
  }

  /// El clima del Inicio del equipo (GET /clima): el de su próxima clase o cita en
  /// su sede, o el de ahora en el negocio. Null si falla o no se sabe.
  Future<ClimaMiembro?> clima() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('$_base/clima');
      final data = res.data?['data'];
      return data is Map<String, dynamic> ? ClimaMiembro.desdeJson(data) : null;
    } on DioException {
      return null;
    }
  }

  Future<List<Profesional>> profesionales() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/instructores');
    return ((res.data?['data'] ?? []) as List).map((e) => Profesional.desdeJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<Asistente>> roster(String sesionId) async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/sesiones/$sesionId/reservas');
    return ((res.data?['data'] ?? []) as List).map((e) => Asistente.desdeJson(e as Map<String, dynamic>)).toList();
  }

  Future<void> marcarAsistencia(String reservaId, String estado) =>
      _dio.post<Map<String, dynamic>>('$_base/reservas/$reservaId/asistencia', data: {'estado': estado});

  Future<void> cobrar(String ordenId, String metodo) =>
      _dio.post<Map<String, dynamic>>('$_base/ordenes/$ordenId/liquidar', data: {'metodo': metodo});

  Future<void> cancelarReserva(String reservaId) =>
      _dio.post<Map<String, dynamic>>('$_base/reservas/$reservaId/cancelar');
}

/// Repositorio ligado a la sesión activa (null si no hay sesión).
final agendaRepositoryProvider = Provider<AgendaRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return AgendaRepository(ref.watch(dioProvider), sesion.slug);
});
