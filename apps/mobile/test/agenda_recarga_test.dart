import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';

/// Lo que el servidor ya hizo (agendar, cancelar, marcar asistencia) no se anuncia
/// como fallido porque después falló volver a cargar la agenda: si no, recepción lo
/// repite y el cliente queda con dos citas. Como ya pasaba al cobrar. Sin red.

Map<String, dynamic> _cita(String id, DateTime inicio) => {
  'id': id,
  'tipo': 'cita',
  'oferta': 'Corte',
  'oferta_id': 'o1',
  'instructor': 'Beto Ramírez',
  'instructor_id': 'p2',
  'inicia_en': inicio.toUtc().toIso8601String(),
  'termina_en': inicio.add(const Duration(hours: 1)).toUtc().toIso8601String(),
  'capacidad': 1,
  'ocupados': 1,
  'en_espera': 0,
  'estado': 'programada',
  'cita': {
    'reserva_id': 'r-$id',
    'cliente': 'Ana López',
    'estado': 'confirmada',
    'estado_atencion': 'confirmada',
  },
};

/// La primera carga sale bien; las siguientes fallan por la red.
class _RepoSinRed extends AgendaRepository {
  _RepoSinRed() : super(Dio(), 'demo');

  var cargas = 0;
  final hechas = <String>[];

  @override
  Future<List<SesionAgenda>> sesiones(DateTime desde, DateTime hasta) async {
    cargas++;
    if (cargas > 1) {
      throw DioException(
        requestOptions: RequestOptions(path: '/sesiones'),
        type: DioExceptionType.connectionError,
      );
    }
    return [SesionAgenda.desdeJson(_cita('c1', DateTime.now()))];
  }

  @override
  Future<List<Profesional>> profesionales() async => const [
    Profesional(id: 'p2', nombre: 'Beto Ramírez'),
  ];

  @override
  Future<SesionAgenda> agendarCita(NuevaCita cita) async {
    hechas.add('agendar');
    return SesionAgenda.desdeJson(_cita('c2', cita.inicia));
  }

  @override
  Future<void> cancelarReserva(
    String reservaId, {
    String por = 'negocio',
  }) async => hechas.add('cancelar:$reservaId');
}

void main() {
  late _RepoSinRed repo;
  late ProviderContainer contenedor;

  setUp(() {
    // Sin red, Riverpod reintenta la recarga por casi un minuto: no se espera.
    AgendaController.esperaRecarga = const Duration(milliseconds: 200);
    repo = _RepoSinRed();
    contenedor = ProviderContainer(
      overrides: [agendaRepositoryProvider.overrideWithValue(repo)],
    );
    addTearDown(contenedor.dispose);
  });

  test(
    'agendar: si la recarga falla, la cita creada se devuelve igual',
    () async {
      // Se mantiene viva la agenda (autoDispose), como en la pantalla.
      final sub = contenedor.listen(agendaProvider, (_, _) {});
      addTearDown(sub.close);
      await contenedor.read(agendaProvider.future);

      final manana = DateTime.now().add(const Duration(days: 1));
      final creada = await contenedor
          .read(agendaProvider.notifier)
          .agendarCita(
            NuevaCita(
              personaId: 'a1',
              ofertaId: 'o1',
              sucursalId: 's1',
              instructorId: 'p2',
              inicia: DateTime(manana.year, manana.month, manana.day, 10),
              duracionMinutos: 30,
            ),
          );

      expect(creada?.id, 'c2');
      expect(repo.hechas, ['agendar']);
      expect(repo.cargas, greaterThan(1));
    },
  );

  test('cancelar: si la recarga falla, no se anuncia como fallida', () async {
    final sub = contenedor.listen(agendaProvider, (_, _) {});
    addTearDown(sub.close);
    final agenda = await contenedor.read(agendaProvider.future);

    await contenedor
        .read(agendaProvider.notifier)
        .cancelar(agenda.sesiones.single);

    expect(repo.hechas, ['cancelar:r-c1']);
    expect(repo.cargas, greaterThan(1));
  });
}
