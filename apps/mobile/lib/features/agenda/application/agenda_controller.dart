import 'package:dio/dio.dart';
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

  /// Cobra lo que falta de la cita (el anticipo sin pagar y el resto, juntos).
  /// Cobrada es lo que responde el servidor: si después falla recargar la agenda,
  /// el cobro ya quedó (no se anuncia como fallido ni se cobra otra vez).
  Future<bool> cobrar(SesionAgenda s, String metodo, {String? clave}) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return false;
    }
    final intento =
        clave ??
        'app-${s.cita!.reservaId}-${DateTime.now().microsecondsSinceEpoch}';
    await repo.cobrarCita(s.cita!.reservaId, metodo, clave: intento);
    if (ref.mounted) {
      ref.invalidateSelf();
      try {
        await future;
      } on Object {
        // La agenda se vuelve a cargar al reintentar; el cobro ya quedó.
      }
    }
    return true;
  }

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

  /// Qué pasaría si no asiste (no cambia nada); null si no se pudo leer, y se
  /// confirma con el texto general, como en la web.
  Future<EfectoInasistencia?> efectoDeInasistencia(SesionAgenda s) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return null;
    }
    try {
      return await repo.efectoDeInasistencia(s.cita!.reservaId);
    } on DioException {
      return null;
    }
  }

  /// Lo que ha pasado con la cita (no cambia nada).
  Future<List<HechoCita>> historial(SesionAgenda s) async {
    final repo = ref.read(agendaRepositoryProvider);
    return repo == null ? const [] : repo.historial(s.cita!.reservaId);
  }

  /// Agenda una cita a un cliente (recepción, teléfono, mostrador), como en la web.
  /// La agenda pasa al día y al profesional de la cita, ya recargada, para verla.
  /// Devuelve la cita creada (null sin sesión).
  Future<SesionAgenda?> agendarCita(NuevaCita cita) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return null;
    }
    final creada = await repo.agendarCita(cita);
    if (!ref.mounted) {
      return creada; // Se salió o cambió la sesión mientras tanto.
    }
    ref
        .read(profesionalAgendaProvider.notifier)
        .elegir(creada.instructorId ?? cita.instructorId);
    final dia = creada.iniciaLocal;
    ref
        .read(fechaAgendaProvider.notifier)
        .elegir(DateTime(dia.year, dia.month, dia.day));
    ref.invalidateSelf();
    await future;
    return creada;
  }

  /// Mueve la cita a otra hora de su sucursal (y, si se quiere, con otro
  /// profesional). El servidor revalida que esté libre; la agenda se recarga.
  Future<CitaMovida?> reprogramar(
    SesionAgenda s, {
    required DateTime inicia,
    String? instructorId,
  }) async {
    CitaMovida? movida;
    await _accion(
      (r) async => movida = await r.reprogramarCita(
        s.cita!.reservaId,
        inicia: inicia,
        instructorId: instructorId,
      ),
    );
    return movida;
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
