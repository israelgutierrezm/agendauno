import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../../perfil/presentation/perfil_screen.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'configuracion_tab.dart';
import 'expediente_tab.dart';
import 'inicio_tab.dart';
import 'pagos_tab.dart';
import 'reservas_tab.dart';

/// Portal del alumno o cliente: Inicio (atención, próxima reserva y accesos
/// directos), Reservas (lista y calendario), Pagos, Expediente y su Cuenta, con la
/// barra de abajo siempre a la mano.
class CuentaScreen extends ConsumerStatefulWidget {
  const CuentaScreen({super.key});

  @override
  ConsumerState<CuentaScreen> createState() => _CuentaScreenState();
}

class _CuentaScreenState extends ConsumerState<CuentaScreen> {
  // Al volver del navegador (pago en línea) se actualiza la cuenta.
  late final AppLifecycleListener _ciclo = AppLifecycleListener(
    onResume: () => ref.invalidate(cuentaProvider),
  );
  PestanaCuenta _pestana = PestanaCuenta.inicio;

  static const _titulos = {
    PestanaCuenta.inicio: 'Mi cuenta',
    PestanaCuenta.reservas: 'Reservas',
    PestanaCuenta.pagos: 'Pagos',
    PestanaCuenta.expediente: 'Expediente',
    PestanaCuenta.cuenta: 'Cuenta',
  };

  @override
  void initState() {
    super.initState();
    _ciclo;
  }

  @override
  void dispose() {
    _ciclo.dispose();
    super.dispose();
  }

  void _ir(PestanaCuenta p) => setState(() => _pestana = p);

  @override
  Widget build(BuildContext context) {
    final estado = ref.watch(cuentaProvider);
    final firmar = estado.value?.consentimientos.length ?? 0;
    final pagar = estado.value?.porPagar.length ?? 0;

    return Scaffold(
      appBar: AppBar(
        title: Text(_titulos[_pestana]!),
        actions: [
          IconButton(
            icon: const Icon(Icons.person_outline),
            tooltip: 'Mi perfil',
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const PerfilScreen()),
            ),
          ),
        ],
      ),
      body: estado.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) =>
            _Error(onReintentar: () => ref.invalidate(cuentaProvider)),
        data: (cuenta) => switch (_pestana) {
          PestanaCuenta.inicio => RefreshIndicator(
            onRefresh: () => ref.refresh(cuentaProvider.future),
            child: InicioTab(cuenta: cuenta, onIr: _ir),
          ),
          PestanaCuenta.reservas => ReservasTab(cuenta: cuenta),
          PestanaCuenta.pagos => PagosTab(cuenta: cuenta),
          PestanaCuenta.expediente => ExpedienteTab(cuenta: cuenta),
          PestanaCuenta.cuenta => const ConfiguracionTab(),
        },
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _pestana.index,
        onDestinationSelected: (i) => _ir(PestanaCuenta.values[i]),
        destinations: [
          const NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'Inicio',
          ),
          const NavigationDestination(
            icon: Icon(Icons.calendar_month_outlined),
            selectedIcon: Icon(Icons.calendar_month),
            label: 'Reservas',
          ),
          NavigationDestination(
            icon: Badge(
              isLabelVisible: pagar > 0,
              label: Text('$pagar'),
              child: const Icon(Icons.payments_outlined),
            ),
            selectedIcon: const Icon(Icons.payments),
            label: 'Pagos',
          ),
          NavigationDestination(
            icon: Badge(
              isLabelVisible: firmar > 0,
              label: Text('$firmar'),
              child: const Icon(Icons.folder_outlined),
            ),
            selectedIcon: const Icon(Icons.folder),
            label: 'Expediente',
          ),
          const NavigationDestination(
            icon: Icon(Icons.settings_outlined),
            selectedIcon: Icon(Icons.settings),
            label: 'Cuenta',
          ),
        ],
      ),
    );
  }
}

/// Ejecuta una acción y muestra el mensaje del servidor si falla.
Future<void> hacerConAviso(
  BuildContext context,
  Future<void> Function() accion, {
  String? exito,
}) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    await accion();
    if (exito != null) {
      messenger.showSnackBar(SnackBar(content: Text(exito)));
    }
  } on DioException catch (e) {
    final data = e.response?.data;
    final msg = (data is Map && data['message'] is String)
        ? data['message'] as String
        : 'No se pudo completar la acción.';
    messenger.showSnackBar(SnackBar(content: Text(msg)));
  }
}

/// Antes de cancelar muestra qué pasará con su crédito (según la política del
/// negocio) y pide confirmación. Devuelve si se canceló.
Future<bool> confirmarCancelacion(
  BuildContext context,
  WidgetRef ref,
  String reservaId,
) async {
  final repo = ref.read(cuentaRepositoryProvider);
  if (repo == null) {
    return false;
  }
  EfectoCancelacion efecto;
  try {
    efecto = await repo.efectoDeCancelar(reservaId);
  } on DioException {
    efecto = const EfectoCancelacion(
      cancelable: true,
      mensaje: '¿Cancelar esta reserva?',
    );
  }
  if (!context.mounted) {
    return false;
  }
  final confirmada = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: const Text('Cancelar reserva'),
      content: Text(efecto.mensaje),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(ctx, false),
          child: const Text('Volver'),
        ),
        if (efecto.cancelable)
          TextButton(
            style: TextButton.styleFrom(foregroundColor: TemaAgendaUno.error),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Sí, cancelar'),
          ),
      ],
    ),
  );
  if (confirmada != true || !context.mounted) {
    return false;
  }
  await hacerConAviso(
    context,
    () => ref.read(cuentaProvider.notifier).cancelar(reservaId),
    exito: 'Reserva cancelada.',
  );
  return true;
}

/// Abre la página de pago de la pasarela en el navegador; al volver a la app la
/// cuenta se actualiza (el webhook confirma el pago).
Future<void> pagarEnLinea(
  BuildContext context,
  WidgetRef ref,
  String ordenId,
) async {
  final repo = ref.read(cuentaRepositoryProvider);
  if (repo == null) {
    return;
  }
  final messenger = ScaffoldMessenger.of(context);
  await hacerConAviso(context, () async {
    final url = await repo.pagarOrden(ordenId);
    if (url == null) {
      await ref.read(cuentaProvider.notifier).recargar();
      return;
    }
    final abierto = await launchUrl(
      Uri.parse(url),
      mode: LaunchMode.externalApplication,
    );
    messenger.showSnackBar(
      SnackBar(
        content: Text(
          abierto
              ? 'Completa el pago en el navegador; al volver actualizamos tu cuenta.'
              : 'No se pudo abrir la página de pago.',
        ),
      ),
    );
  });
}

class _Error extends StatelessWidget {
  const _Error({required this.onReintentar});

  final VoidCallback onReintentar;

  @override
  Widget build(BuildContext context) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Text('No se pudo cargar tu cuenta.'),
        const SizedBox(height: 8),
        OutlinedButton(
          onPressed: onReintentar,
          child: const Text('Reintentar'),
        ),
      ],
    ),
  );
}
