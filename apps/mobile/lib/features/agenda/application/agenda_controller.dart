import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/agenda_models.dart';
import '../data/agenda_repository.dart';

/// Día que se está viendo en la agenda (hoy por defecto).
class FechaAgenda extends Notifier<DateTime> {
  @override
  DateTime build() {
    final hoy = DateTime.now();
    return DateTime(hoy.year, hoy.month, hoy.day);
  }

  void elegir(DateTime dia) => state = DateTime(dia.year, dia.month, dia.day);
}

final fechaAgendaProvider = NotifierProvider<FechaAgenda, DateTime>(FechaAgenda.new);

/// Profesional elegido en la agenda de citas (null = el primero).
class ProfesionalAgenda extends Notifier<String?> {
  @override
  String? build() => null;

  void elegir(String? id) => state = id;
}

final profesionalAgendaProvider = NotifierProvider<ProfesionalAgenda, String?>(ProfesionalAgenda.new);

/// Lo que muestra la agenda de un día: sus sesiones y los profesionales.
class AgendaDia {
  const AgendaDia({required this.sesiones, required this.profesionales});

  final List<SesionAgenda> sesiones;
  final List<Profesional> profesionales;
}

/// Carga el día elegido y ejecuta las acciones de recepción (recarga al terminar).
class AgendaController extends AsyncNotifier<AgendaDia> {
  @override
  Future<AgendaDia> build() async {
    final repo = ref.watch(agendaRepositoryProvider);
    final dia = ref.watch(fechaAgendaProvider);
    if (repo == null) {
      return const AgendaDia(sesiones: [], profesionales: []);
    }
    final resultados = await Future.wait([repo.sesionesDelDia(dia), repo.profesionales()]);
    return AgendaDia(sesiones: resultados[0] as List<SesionAgenda>, profesionales: resultados[1] as List<Profesional>);
  }

  Future<void> marcarLlegada(SesionAgenda s) => _accion((r) => r.marcarAsistencia(s.cita!.reservaId, 'presente'));

  Future<void> marcarNoAsistio(SesionAgenda s) => _accion((r) => r.marcarAsistencia(s.cita!.reservaId, 'ausente'));

  Future<void> cobrar(SesionAgenda s, String metodo) => _accion((r) => r.cobrar(s.cita!.ordenId!, metodo));

  Future<void> cancelar(SesionAgenda s) => _accion((r) => r.cancelarReserva(s.cita!.reservaId));

  Future<void> _accion(Future<void> Function(AgendaRepository repo) fn) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    await fn(repo);
    ref.invalidateSelf();
    await future;
  }
}

final agendaProvider = AsyncNotifierProvider<AgendaController, AgendaDia>(AgendaController.new);
