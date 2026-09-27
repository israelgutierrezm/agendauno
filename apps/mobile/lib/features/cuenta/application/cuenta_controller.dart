import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../auth/application/sesion_controller.dart';
import '../data/corte_planes.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';

/// Carga y acciones de Mi cuenta. Tras cada acción recarga el estado para reflejar
/// créditos, reservas y consentimientos actualizados.
class CuentaController extends AsyncNotifier<MiCuenta> {
  @override
  Future<MiCuenta> build() async {
    final repo = ref.watch(cuentaRepositoryProvider);
    if (repo == null) {
      return const MiCuenta(derechos: [], reservas: [], clases: []);
    }

    return repo.cargar(conClases: _conClases);
  }

  /// En negocios de citas no hay clases que listar: se agenda una cita.
  bool get _conClases => ref.read(sesionProvider)?.esCitas != true;

  Future<void> reservar(String sesionId, {bool esperar = false}) =>
      _hacer((repo) => repo.reservar(sesionId, esperar: esperar));

  Future<void> cancelar(String reservaId) =>
      _hacer((repo) => repo.cancelar(reservaId));

  Future<void> reprogramar(
    String reservaId, {
    String? iniciaEnLocal,
    String? sesionId,
  }) => _hacer(
    (repo) => repo.reprogramar(
      reservaId,
      iniciaEnLocal: iniciaEnLocal,
      sesionId: sesionId,
    ),
  );

  Future<void> aceptarLugar(String reservaId) =>
      _hacer((repo) => repo.aceptarLugar(reservaId));

  Future<void> firmar(String consentimientoId) =>
      _hacer((repo) => repo.firmar(consentimientoId));

  Future<void> calificar(
    String reservaId,
    int calificacion,
    String? comentario,
  ) => _hacer((repo) => repo.calificar(reservaId, calificacion, comentario));

  /// Compra un plan: la orden aparece en "Por pagar" al recargar.
  Future<void> comprar(String productoId) =>
      _hacer((repo) => repo.comprar(productoId));

  /// Tras agendar una cita (la agenda el formulario de citas).
  Future<void> recargar() => _hacer((_) async {});

  Future<void> _hacer(
    Future<void> Function(CuentaRepository repo) accion,
  ) async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    await accion(repo);
    state = await AsyncValue.guard(() => repo.cargar(conClases: _conClases));
  }
}

final cuentaProvider = AsyncNotifierProvider<CuentaController, MiCuenta>(
  CuentaController.new,
);

/// Los planes que puede comprar (se filtran las clases extra si no tiene paquete).
final productosProvider = FutureProvider<List<ProductoComprable>>((ref) async {
  final cuenta = await ref.watch(cuentaProvider.future);
  final repo = ref.watch(cuentaRepositoryProvider);
  if (repo == null) {
    return const [];
  }
  return ProductoComprable.paraComprar(await repo.productos(), cuenta.derechos);
});

/// El clima del Inicio; se vuelve a pedir con la cuenta (su próxima reserva manda).
final climaProvider = FutureProvider<ClimaMiembro?>((ref) async {
  await ref.watch(cuentaProvider.future);
  final repo = ref.watch(cuentaRepositoryProvider);
  return repo?.clima();
});

/// Corte de sus planes; se vuelve a pedir cada vez que la cuenta se recarga (p. ej.
/// tras reservar, cancelar o pagar).
final cortePlanesProvider = FutureProvider<List<PlanCorte>>((ref) async {
  await ref.watch(cuentaProvider.future);
  final repo = ref.watch(cuentaRepositoryProvider);
  return repo == null ? const [] : repo.planes();
});
