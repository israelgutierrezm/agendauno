import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';

/// El cobro de una cita desde la app del equipo, como en la web: se cobra por el
/// cobro de la cita (`/reservas/{id}/cobro`, que con anticipo cobra el saldo), no
/// liquidando la orden original. Cobrada es lo que responde el servidor: si después
/// falla recargar la agenda, el cobro ya quedó. Datos sintéticos.

Map<String, dynamic> _cita({required bool pagada}) {
  final ahora = DateTime.now();
  return {
    'id': 's1',
    'tipo': 'cita',
    'oferta': 'Corte',
    'inicia_en': ahora.toUtc().toIso8601String(),
    'termina_en': ahora.add(const Duration(hours: 1)).toUtc().toIso8601String(),
    'capacidad': 1,
    'ocupados': 1,
    'en_espera': 0,
    'estado': 'programada',
    'cita': {
      'reserva_id': 'r1',
      'cliente': 'Ana López',
      'estado': 'confirmada',
      // La orden original es la del anticipo, ya pagada; falta el saldo.
      'orden_id': 'o-anticipo',
      'estado_pago': pagada ? 'pagada' : 'por_cobrar',
      'total_minor': 25000,
      'anticipo_minor': 10000,
      'por_cobrar_minor': pagada ? null : 15000,
      'moneda': 'MXN',
    },
  };
}

class _Repo extends AgendaRepository {
  _Repo({required this.cobraDeVerdad, this.recargaFalla = false})
    : super(Dio(), 'demo');

  /// Si el servidor deja la cita pagada tras el cobro.
  final bool cobraDeVerdad;

  /// Si recargar la agenda después del cobro falla (sin red, por ejemplo).
  final bool recargaFalla;
  final cobros = <String>[];
  var pagada = false;

  @override
  Future<List<SesionAgenda>> sesiones(DateTime desde, DateTime hasta) async {
    if (recargaFalla && cobros.isNotEmpty) {
      throw DioException(requestOptions: RequestOptions());
    }
    return [SesionAgenda.desdeJson(_cita(pagada: pagada))];
  }

  @override
  Future<List<Profesional>> profesionales() async => const [];

  @override
  Future<void> cobrarCita(
    String reservaId,
    String metodo, {
    required String clave,
  }) async {
    cobros.add('$reservaId:$metodo');
    pagada = cobraDeVerdad;
  }
}

Future<(bool, _Repo)> _cobrar({
  required bool cobraDeVerdad,
  bool recargaFalla = false,
}) async {
  final repo = _Repo(cobraDeVerdad: cobraDeVerdad, recargaFalla: recargaFalla);
  final c = ProviderContainer(
    overrides: [agendaRepositoryProvider.overrideWithValue(repo)],
  );
  addTearDown(c.dispose);
  final dia = await c.read(agendaProvider.future);
  final cita = dia.sesiones.single;
  expect(cita.porCobrarMinor, 15000);
  final cobrada = await c
      .read(agendaProvider.notifier)
      .cobrar(cita, 'efectivo');
  return (cobrada, repo);
}

void main() {
  test(
    'cobra por el cobro de la cita (el saldo), no la orden del anticipo',
    () async {
      final (cobrada, repo) = await _cobrar(cobraDeVerdad: true);

      expect(repo.cobros, ['r1:efectivo']);
      expect(cobrada, isTrue);
    },
  );

  test(
    'si el cobro quedó pero falla recargar la agenda, se da por cobrada',
    () async {
      final (cobrada, repo) = await _cobrar(
        cobraDeVerdad: true,
        recargaFalla: true,
      );

      expect(repo.cobros, ['r1:efectivo']);
      expect(cobrada, isTrue);
    },
  );
}
