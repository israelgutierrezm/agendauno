import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/calendario/calendario.dart';
import '../../agenda/data/agenda_models.dart';
import '../../agenda/data/agenda_repository.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../cuenta/data/cuenta_models.dart';

/// Rango de fechas que se ve en "Mis clases" (lo fija el calendario al moverse).
/// Vuelve al de hoy al cambiar de sesión, como el día de la agenda. Son días del
/// negocio: al abrir, desde hoy allá (no el día del teléfono).
class RangoMisClases extends Notifier<({DateTime desde, DateTime hasta})> {
  @override
  ({DateTime desde, DateTime hasta}) build() {
    ref.watch(sesionProvider.select((s) => (s?.slug, s?.bearer)));
    final hoy = hoyDelNegocio(ref.read(sesionProvider), DateTime.now());
    return (desde: hoy, hasta: Calendario.mas(hoy, 30));
  }

  void fijar(DateTime desde, DateTime hasta) {
    if (desde != state.desde || hasta != state.hasta) {
      state = (desde: desde, hasta: hasta);
    }
  }
}

final rangoMisClasesProvider =
    NotifierProvider<RangoMisClases, ({DateTime desde, DateTime hasta})>(
      RangoMisClases.new,
    );

/// El día de hoy en el negocio de [sesion] (con el desfase de su zona); sin
/// sesión, el del teléfono.
DateTime hoyDelNegocio(Sesion? sesion, DateTime ahora) =>
    sesion?.hoyEnNegocio(ahora) ?? Calendario.dia(ahora);

/// Lo que imparte en el rango visible (solo lo programado). Como todo lo de una
/// sesión, se descarta al salir del portal (no pasa a la siguiente sesión).
final misClasesProvider = FutureProvider.autoDispose<List<SesionAgenda>>((
  ref,
) async {
  final repo = ref.watch(agendaRepositoryProvider);
  final rango = ref.watch(rangoMisClasesProvider);
  if (repo == null) {
    return const [];
  }
  final sesiones = await repo.sesiones(rango.desde, rango.hasta);
  return sesiones.where((s) => s.programada).toList();
});

/// Lo de hoy y los próximos 6 días (para su Inicio), en los días de la sucursal.
final proximasMisClasesProvider = FutureProvider.autoDispose<List<SesionAgenda>>((
  ref,
) async {
  final repo = ref.watch(agendaRepositoryProvider);
  if (repo == null) {
    return const [];
  }
  final ahora = DateTime.now();
  final hoy = hoyDelNegocio(ref.read(sesionProvider), ahora);
  // Un día más por lado: el «hoy» de cada sucursal puede no ser el del negocio.
  final sesiones = await repo.sesiones(
    Calendario.mas(hoy, -1),
    Calendario.mas(hoy, 7),
  );
  return deLosProximosDias(sesiones.where((s) => s.programada).toList(), ahora);
});

/// Lo de los próximos [dias] días (hoy incluido), con el «hoy» de la sucursal de
/// cada sesión: una clase de hoy en su sucursal cuenta aunque en el teléfono ya
/// sea mañana, y una de ayer en la sucursal no, aunque en el teléfono sea hoy.
List<SesionAgenda> deLosProximosDias(
  List<SesionAgenda> sesiones,
  DateTime ahora, {
  int dias = 7,
}) => [
  for (final s in sesiones)
    if (_enLosProximosDias(s, ahora, dias)) s,
];

bool _enLosProximosDias(SesionAgenda s, DateTime ahora, int dias) {
  final hoy = s.hoyEnSucursal(ahora);
  final dia = Calendario.dia(s.iniciaLocal);
  return !dia.isBefore(hoy) && dia.isBefore(Calendario.mas(hoy, dias));
}

/// El clima de su Inicio (el de su próxima clase o cita en su sede); se vuelve a
/// pedir con sus clases.
final climaEquipoProvider = FutureProvider.autoDispose<ClimaMiembro?>((
  ref,
) async {
  await ref.watch(proximasMisClasesProvider.future);
  final repo = ref.watch(agendaRepositoryProvider);
  return repo?.clima();
});

/// Cupo de una clase ("6/10") o, en una cita, con quién.
String cupoDe(SesionAgenda s) {
  switch (s.tipo) {
    case TipoSesion.cita:
      return s.cita?.cliente ?? '';
    case TipoSesion.clase:
      final ocupados = s.clase?.ocupados ?? s.ocupados;
      final capacidad = s.clase?.capacidad;
      return capacidad != null ? '$ocupados/$capacidad' : '$ocupados';
  }
}

/// Lo que pinta su calendario: todo es suyo (resaltado); lo que tiene lista de
/// espera, en ámbar. En el día y la hora de la sucursal.
List<EventoCal> eventosDeClases(List<SesionAgenda> sesiones) => [
  for (final s in sesiones)
    EventoCal(
      id: s.id,
      titulo: s.oferta ?? '—',
      inicio: s.iniciaLocal,
      fin: s.terminaLocal,
      detalle: [s.sucursal, s.sala].whereType<String>().join(' · '),
      estado: cupoDe(s),
      tono: s.enEspera > 0 ? TonoEvento.aviso : TonoEvento.suave,
      destacado: true,
    ),
];
