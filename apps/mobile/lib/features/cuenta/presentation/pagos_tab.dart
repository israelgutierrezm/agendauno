import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import 'corte_planes_card.dart';
import 'cuenta_widgets.dart';
import 'pago_automatico_screen.dart';

/// Pagos del portal: lo que tiene por pagar, el corte de sus planes y el pago
/// automático si el negocio lo ofrece.
class PagosTab extends ConsumerWidget {
  const PagosTab({super.key, required this.cuenta});

  final MiCuenta cuenta;

  @override
  Widget build(BuildContext context, WidgetRef ref) => RefreshIndicator(
    onRefresh: () => ref.refresh(cuentaProvider.future),
    child: ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
      children: [
        const TituloSeccion('Por pagar'),
        if (cuenta.porPagar.isEmpty)
          const TextoVacio('Estás al corriente.')
        else
          for (final o in cuenta.porPagar)
            PorPagarTile(o, pagoEnLinea: cuenta.pagoEnLinea),
        // El corte de cada plan: qué incluía, cómo lo usó y lo que le queda.
        const TituloSeccion('Mis planes'),
        const CortePlanesSeccion(),
        if (cuenta.pagoAutomatico) ...[
          const TituloSeccion('Pago automático'),
          Card(
            child: ListTile(
              leading: const Icon(Icons.autorenew),
              title: const Text('Pago automático'),
              subtitle: const Text('Tu membresía se cobra sola'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => const PagoAutomaticoScreen(),
                ),
              ),
            ),
          ),
        ],
      ],
    ),
  );
}
