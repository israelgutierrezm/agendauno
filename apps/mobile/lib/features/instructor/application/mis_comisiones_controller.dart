import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../auth/application/sesion_controller.dart';
import '../data/comisiones_repository.dart';
import '../data/mis_comisiones.dart';
import 'mis_clases_controller.dart';

/// El periodo de «Mis comisiones»: al abrir, del día 1 del mes a hoy en el negocio
/// (como en la web). Se descarta al salir de la pantalla.
class PeriodoComisiones extends Notifier<({DateTime desde, DateTime hasta})> {
  @override
  ({DateTime desde, DateTime hasta}) build() {
    ref.watch(sesionProvider.select((s) => (s?.slug, s?.bearer)));
    final hoy = hoyDelNegocio(ref.read(sesionProvider), DateTime.now());
    return (desde: DateTime(hoy.year, hoy.month), hasta: hoy);
  }

  void fijar(DateTime desde, DateTime hasta) {
    if (desde != state.desde || hasta != state.hasta) {
      state = (desde: desde, hasta: hasta);
    }
  }
}

final periodoComisionesProvider =
    NotifierProvider.autoDispose<
      PeriodoComisiones,
      ({DateTime desde, DateTime hasta})
    >(PeriodoComisiones.new);

/// Lo suyo del periodo elegido (null sin sesión).
final misComisionesProvider = FutureProvider.autoDispose<MisComisiones?>((
  ref,
) async {
  final repo = ref.watch(comisionesRepositoryProvider);
  final periodo = ref.watch(periodoComisionesProvider);
  return repo?.delPeriodo(periodo.desde, periodo.hasta);
});
