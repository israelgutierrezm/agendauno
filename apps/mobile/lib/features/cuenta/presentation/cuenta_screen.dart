import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../notificaciones/application/aviso_abierto.dart';
import '../../perfil/presentation/boton_mi_perfil.dart';
import '../../widget_inicio/application/widget_inicio.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'configuracion_tab.dart';
import 'expediente_tab.dart';
import 'inicio_tab.dart';
import 'pagos_tab.dart';
import 'personas_a_cargo.dart';
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

  /// En un negocio de citas no se reserva un lugar: se agenda una cita (como en
  /// la web).
  static String _titulo(PestanaCuenta p, {required bool esCitas}) =>
      esCitas && p == PestanaCuenta.reservas ? 'Citas' : _titulos[p]!;

  @override
  void initState() {
    super.initState();
    _ciclo;
    // El widget de la pantalla de inicio sigue a su cuenta: al abrir la app, al
    // volver a ella y tras reservar, cancelar o pagar (la cuenta se recarga).
    ref.listenManual<AsyncValue<MiCuenta>>(
      cuentaProvider,
      (_, estado) => actualizarWidgetInicio(ref, estado),
      fireImmediately: true,
    );
    // Al tocar una notificación se abre lo suyo: su reserva o cita, o sus pagos.
    escucharAvisos(
      this,
      (destino) => _ir(switch (destino) {
        DestinoPush.reservas => PestanaCuenta.reservas,
        DestinoPush.pagos => PestanaCuenta.pagos,
        DestinoPush.inicio || DestinoPush.mensajes => PestanaCuenta.inicio,
      }),
    );
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
    final esCitas = ref.watch(sesionProvider)?.esCitas ?? false;

    return Scaffold(
      appBar: AppBar(
        title: Text(_titulo(_pestana, esCitas: esCitas)),
        actions: [const BotonMiPerfil()],
      ),
      body: Column(
        children: [
          // De quién es la cuenta: la suya o la de una persona a su cargo (ADR 0123).
          const SelectorParticipante(),
          Expanded(
            child: estado.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => ErrorDeCarga(
                mensaje: 'No se pudo cargar tu cuenta.',
                onReintentar: () => ref.invalidate(cuentaProvider),
              ),
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
          ),
        ],
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
          NavigationDestination(
            icon: const Icon(Icons.calendar_month_outlined),
            selectedIcon: const Icon(Icons.calendar_month),
            label: _titulo(PestanaCuenta.reservas, esCitas: esCitas),
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

/// Ejecuta una acción y, si falla, dice por qué (la razón del servidor).
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
    messenger.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
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
    // Sin la vista previa no se sabe si pierde lo pagado: se advierte.
    efecto = const EfectoCancelacion(
      cancelable: true,
      mensaje:
          'No pudimos revisar qué pasa si cancelas ahora: según la política del '
          'negocio, podrías perder el crédito o lo que pagaste. ¿Cancelar de '
          'todos modos?',
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
            style: TextButton.styleFrom(foregroundColor: context.colores.error),
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

/// Paga en línea una orden, como en la web: con tarjeta abre la página de la
/// pasarela en el navegador (al volver, la cuenta se actualiza: el webhook confirma
/// el pago); en OXXO muestra la ficha con su referencia; si no, el pago queda en
/// proceso. Con [domiciliar], la tarjeta queda para el pago automático.
Future<void> pagarEnLinea(
  BuildContext context,
  WidgetRef ref,
  String ordenId, {
  String metodo = 'tarjeta',
  bool domiciliar = false,
}) async {
  final repo = ref.read(cuentaRepositoryProvider);
  if (repo == null) {
    return;
  }
  final messenger = ScaffoldMessenger.of(context);
  await hacerConAviso(context, () async {
    final checkout = await repo.pagarOrden(
      ordenId,
      metodo: metodo,
      domiciliar: domiciliar,
    );
    switch (checkout) {
      case IrAPagarEnLinea(:final url):
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
      case FichaDePago ficha:
        await ref.read(cuentaProvider.notifier).recargar();
        if (context.mounted) {
          await mostrarFichaDePago(context, ficha);
        }
      case PagoPorConfirmar():
        await ref.read(cuentaProvider.notifier).recargar();
        messenger.showSnackBar(
          const SnackBar(
            content: Text(
              'Tu pago está en proceso. Se confirmará en cuanto la pasarela lo '
              'apruebe.',
            ),
          ),
        );
    }
  });
}

/// La ficha para pagar en tienda: la referencia (se puede copiar), el código de
/// barras y el recibo para imprimir. La compra se confirma sola al pagar.
Future<void> mostrarFichaDePago(BuildContext context, FichaDePago ficha) =>
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (contexto) => SafeArea(
        child: Padding(
          key: const Key('ficha-de-pago'),
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Paga en tienda',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              SelectableText('Referencia: ${ficha.referencia}'),
              if (ficha.codigoBarras != null) ...[
                const SizedBox(height: 8),
                Image.network(
                  ficha.codigoBarras!,
                  height: 56,
                  errorBuilder: (_, _, _) => const SizedBox.shrink(),
                ),
              ],
              const SizedBox(height: 8),
              Text(
                'Presenta la referencia en la caja. Tu compra se confirma sola '
                'cuando la tienda reporta el pago.',
                style: TextStyle(color: context.colores.textoSuave),
              ),
              Wrap(
                spacing: 8,
                children: [
                  TextButton.icon(
                    icon: const Icon(Icons.copy, size: 18),
                    label: const Text('Copiar referencia'),
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: ficha.referencia));
                      ScaffoldMessenger.of(contexto).showSnackBar(
                        const SnackBar(content: Text('Referencia copiada.')),
                      );
                    },
                  ),
                  if (ficha.recibo != null)
                    TextButton(
                      onPressed: () => launchUrl(
                        Uri.parse(ficha.recibo!),
                        mode: LaunchMode.externalApplication,
                      ),
                      child: const Text('Ver recibo para imprimir'),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
