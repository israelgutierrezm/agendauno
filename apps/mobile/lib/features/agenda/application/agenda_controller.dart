import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../auth/application/sesion_controller.dart';
import '../data/agenda_models.dart';
import '../data/agenda_repository.dart';

/// Día que se está viendo en la agenda (hoy por defecto). Vuelve a hoy al cambiar
/// de sesión: quien entra después no arranca en el día que dejó el anterior. Es un
/// día de la sucursal (así se filtran las sesiones); al abrir, antes de cargarlas,
/// es el de hoy en el negocio (con el desfase de su zona; ADR 0166) y, con un API
/// anterior, el del teléfono.
class FechaAgenda extends Notifier<DateTime> {
  @override
  DateTime build() {
    ref.watch(sesionProvider.select((s) => (s?.slug, s?.bearer)));
    final ahora = DateTime.now();
    return ref.read(sesionProvider)?.hoyEnNegocio(ahora) ??
        DateTime(ahora.year, ahora.month, ahora.day);
  }

  void elegir(DateTime dia) => state = DateTime(dia.year, dia.month, dia.day);

  /// La semana anterior o la siguiente (mismo día de la semana).
  void moverSemanas(int n) => elegir(state.add(Duration(days: 7 * n)));

  /// Vuelve a hoy: [dia] es el de hoy en la sucursal si ya se sabe (ver
  /// [AgendaDia.hoyEnSucursal]); si no, el del negocio (o el del teléfono).
  void hoy([DateTime? dia]) => elegir(
    dia ??
        ref.read(sesionProvider)?.hoyEnNegocio(DateTime.now()) ??
        DateTime.now(),
  );
}

final fechaAgendaProvider = NotifierProvider<FechaAgenda, DateTime>(
  FechaAgenda.new,
);

/// Profesional elegido en la agenda de citas (null = el primero).
class ProfesionalAgenda extends Notifier<String?> {
  @override
  String? build() => null;

  void elegir(String? id) => state = id;
}

// De la sesión (un profesional de ESTE negocio): se descarta al salir.
final profesionalAgendaProvider =
    NotifierProvider.autoDispose<ProfesionalAgenda, String?>(
      ProfesionalAgenda.new,
    );

/// Lo que muestra la agenda de un día: sus sesiones y los profesionales.
class AgendaDia {
  const AgendaDia({required this.sesiones, required this.profesionales});

  final List<SesionAgenda> sesiones;
  final List<Profesional> profesionales;

  /// El día de hoy en la sucursal (con el desfase de sus sesiones), o null si el
  /// día no trae sesiones con desfase (un día vacío): entonces vale el del negocio
  /// (`Sesion.hoyEnNegocio`), no el del teléfono.
  DateTime? hoyEnSucursal(DateTime ahora) => sesiones
      .where((s) => s.desfaseMin != null)
      .firstOrNull
      ?.hoyEnSucursal(ahora);
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
    final resultados = await Future.wait([
      repo.sesionesDelDia(dia),
      repo.profesionales(),
    ]);
    return AgendaDia(
      sesiones: resultados[0] as List<SesionAgenda>,
      profesionales: resultados[1] as List<Profesional>,
    );
  }

  Future<void> marcarLlegada(SesionAgenda s, {bool retardo = false}) => _accion(
    (r) => r.marcarAsistencia(s.cita!.reservaId, 'presente', retardo: retardo),
  );

  Future<void> marcarNoAsistio(SesionAgenda s) =>
      _accion((r) => r.marcarAsistencia(s.cita!.reservaId, 'ausente'));

  Future<void> cobrar(SesionAgenda s, String metodo) =>
      _accion((r) => r.cobrar(s.cita!.ordenId!, metodo));

  Future<void> cancelar(SesionAgenda s, {String por = 'negocio'}) =>
      _accion((r) => r.cancelarReserva(s.cita!.reservaId, por: por));

  /// Qué pasaría al cancelarla ahora (no cambia nada).
  Future<EfectoCancelacion> efectoDeCancelar(
    SesionAgenda s, {
    String por = 'negocio',
  }) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return const EfectoCancelacion(cancelable: false, mensaje: '');
    }
    return repo.efectoDeCancelar(s.cita!.reservaId, por: por);
  }

  /// Lo que ha pasado con la cita (no cambia nada).
  Future<List<HechoCita>> historial(SesionAgenda s) async {
    final repo = ref.read(agendaRepositoryProvider);
    return repo == null ? const [] : repo.historial(s.cita!.reservaId);
  }

  Future<void> _accion(Future<void> Function(AgendaRepository repo) fn) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    await fn(repo);
    if (!ref.mounted) {
      return; // Se salió o cambió la sesión mientras tanto.
    }
    ref.invalidateSelf();
    await future;
  }
}

// Lo de una sesión se descarta al salir: la siguiente no arranca con el valor anterior.
final agendaProvider =
    AsyncNotifierProvider.autoDispose<AgendaController, AgendaDia>(
      AgendaController.new,
    );
