import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
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

  Future<List<SesionAgenda>> sesionesDelDia(DateTime dia) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/sesiones',
      queryParameters: {'desde': ymd(dia), 'hasta': ymd(dia)},
    );
    final objetivo = ymd(dia);
    return ((res.data?['data'] ?? []) as List)
        .map((e) => SesionAgenda.desdeJson(e as Map<String, dynamic>))
        // El servidor ensancha la ventana un día por lado (zonas): se filtra el día local.
        .where((s) => ymd(s.iniciaEn) == objetivo)
        .toList()
      ..sort((a, b) => a.iniciaEn.compareTo(b.iniciaEn));
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
