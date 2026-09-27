import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/calendario/calendario.dart';
import '../../agenda/data/agenda_models.dart';
import '../../agenda/data/agenda_repository.dart';

/// Rango de fechas que se ve en "Mis clases" (lo fija el calendario al moverse).
class RangoMisClases extends Notifier<({DateTime desde, DateTime hasta})> {
  @override
  ({DateTime desde, DateTime hasta}) build() {
    final hoy = Calendario.dia(DateTime.now());
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

/// Lo de hoy y los próximos 6 días (para su Inicio).
final proximasMisClasesProvider =
    FutureProvider.autoDispose<List<SesionAgenda>>((ref) async {
      final repo = ref.watch(agendaRepositoryProvider);
      if (repo == null) {
        return const [];
      }
      final hoy = Calendario.dia(DateTime.now());
      final sesiones = await repo.sesiones(hoy, Calendario.mas(hoy, 6));
      return sesiones.where((s) => s.programada).toList();
    });

/// Cupo de una clase ("6/10") o, en una cita, con quién.
String cupoDe(SesionAgenda s) {
  if (s.esCita) {
    return s.cita?.cliente ?? '';
  }
  return s.capacidad != null ? '${s.ocupados}/${s.capacidad}' : '${s.ocupados}';
}

/// Lo que pinta su calendario: todo es suyo (resaltado); lo que tiene lista de
/// espera, en ámbar.
List<EventoCal> eventosDeClases(List<SesionAgenda> sesiones) => [
  for (final s in sesiones)
    EventoCal(
      id: s.id,
      titulo: s.oferta ?? '—',
      inicio: s.iniciaEn,
      fin: s.terminaEn,
      detalle: [s.sucursal, s.sala].whereType<String>().join(' · '),
      estado: cupoDe(s),
      tono: s.enEspera > 0 ? TonoEvento.aviso : TonoEvento.suave,
      destacado: true,
    ),
];
