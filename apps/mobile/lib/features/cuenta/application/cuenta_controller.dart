import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../auth/application/sesion_controller.dart';
import '../data/corte_planes.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';

/// Carga y acciones de Mi cuenta. Tras cada acción recarga el estado para reflejar
/// créditos, reservas y consentimientos actualizados.
///
/// Todo lo de Mi cuenta es de UNA sesión: estos proveedores se descartan al salir
/// del portal (autoDispose; cambiar de cuenta o de negocio siempre pasa por la
/// pantalla de acceso), así que la siguiente sesión empieza sin el valor anterior
/// —Riverpod lo conservaría al recargar o fallar— y lo que responda tarde la sesión
/// anterior ya no se escribe (`ref.mounted`).
class CuentaController extends AsyncNotifier<MiCuenta> {
  @override
  Future<MiCuenta> build() async {
    final repo = ref.watch(cuentaRepositoryProvider);
    if (repo == null) {
      return const MiCuenta(derechos: [], reservas: [], clases: []);
    }

    return repo.cargar(conClases: _conClases);
  }

  /// Solo un negocio de clases tiene clases que listar; en uno de citas se agenda
  /// una cita (lo dicen las capacidades de la sesión).
  bool get _conClases => ref.read(sesionProvider)?.capacidades.clases ?? true;

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
    if (!ref.mounted) {
      return; // Se salió o cambió la sesión mientras tanto.
    }
    final nuevo = await AsyncValue.guard(
      () => repo.cargar(conClases: _conClases),
    );
    if (ref.mounted) {
      state = nuevo;
    }
  }
}

final cuentaProvider =
    AsyncNotifierProvider.autoDispose<CuentaController, MiCuenta>(
      CuentaController.new,
    );

/// Lo que ve el calendario de Reservas: el periodo (lo avisa el calendario al
/// moverse) y la sucursal elegida (vacía = todas).
class FiltroAgenda
    extends Notifier<({DateTime desde, DateTime hasta, String sucursal})> {
  @override
  ({DateTime desde, DateTime hasta, String sucursal}) build() {
    final hoy = DateTime.now();
    final dia = DateTime(hoy.year, hoy.month, hoy.day);
    return (desde: dia, hasta: dia.add(const Duration(days: 30)), sucursal: '');
  }

  void fijarPeriodo(DateTime desde, DateTime hasta) {
    if (desde != state.desde || hasta != state.hasta) {
      state = (desde: desde, hasta: hasta, sucursal: state.sucursal);
    }
  }

  void elegirSucursal(String sucursal) =>
      state = (desde: state.desde, hasta: state.hasta, sucursal: sucursal);
}

final filtroAgendaProvider =
    NotifierProvider.autoDispose<
      FiltroAgenda,
      ({DateTime desde, DateTime hasta, String sucursal})
    >(FiltroAgenda.new);

/// Las clases del periodo y sede que se ven; se vuelven a pedir al moverse de
/// periodo o de sede, y tras reservar o cancelar (cambia el cupo).
final clasesPeriodoProvider = FutureProvider.autoDispose<AgendaPeriodo>((
  ref,
) async {
  final filtro = ref.watch(filtroAgendaProvider);
  await ref.watch(cuentaProvider.future);
  final repo = ref.watch(cuentaRepositoryProvider);
  if (repo == null) {
    return const AgendaPeriodo(clases: []);
  }
  return repo.agendaPeriodo(
    filtro.desde,
    filtro.hasta,
    sucursalId: filtro.sucursal,
  );
});

/// Los planes que puede comprar (se filtran las clases extra si no tiene paquete).
/// Si el plan del negocio no incluye la venta en línea (ADR 0107), ninguno.
final productosProvider = FutureProvider.autoDispose<List<ProductoComprable>>((
  ref,
) async {
  final cuenta = await ref.watch(cuentaProvider.future);
  final repo = ref.watch(cuentaRepositoryProvider);
  final ventaEnLinea =
      ref.read(sesionProvider)?.tieneFuncion('venta_en_linea') ?? true;
  if (repo == null || !ventaEnLinea) {
    return const [];
  }
  return ProductoComprable.paraComprar(await repo.productos(), cuenta.derechos);
});

/// El clima del Inicio; se vuelve a pedir con la cuenta (su próxima reserva manda).
final climaProvider = FutureProvider.autoDispose<ClimaMiembro?>((ref) async {
  await ref.watch(cuentaProvider.future);
  final repo = ref.watch(cuentaRepositoryProvider);
  return repo?.clima();
});

/// Corte de sus planes; se vuelve a pedir cada vez que la cuenta se recarga (p. ej.
/// tras reservar, cancelar o pagar).
final cortePlanesProvider = FutureProvider.autoDispose<List<PlanCorte>>((
  ref,
) async {
  await ref.watch(cuentaProvider.future);
  final repo = ref.watch(cuentaRepositoryProvider);
  return repo == null ? const [] : repo.planes();
});
