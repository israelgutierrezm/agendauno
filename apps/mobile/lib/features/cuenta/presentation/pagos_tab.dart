import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import 'comprar_planes.dart';
import 'compras_mostrador.dart';
import 'corte_planes_card.dart';
import 'cuenta_widgets.dart';
import 'historial_compras.dart';
import 'pago_automatico_screen.dart';

/// Pagos del portal: lo que tiene por pagar, comprar un plan, el corte de sus
/// planes, el pago automático si el negocio lo ofrece, su historial de pagos y lo
/// que compró en el mostrador.
class PagosTab extends ConsumerWidget {
  const PagosTab({super.key, required this.cuenta});

  final MiCuenta cuenta;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // En citas no hay nada que se renueve (ADR 0130).
    final esCitas = ref.watch(sesionProvider)?.esCitas ?? false;
    return RefreshIndicator(
      onRefresh: () => ref.refresh(cuentaProvider.future),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
        children: [
          const TituloSeccion('Por pagar'),
          // La pasarela del negocio está en pruebas (M07).
          if (cuenta.pagoEnLinea && cuenta.pagoEnLineaPrueba)
            Padding(
              key: Key('modo-prueba'),
              padding: EdgeInsets.only(bottom: 8),
              child: Text(
                'Este negocio está probando su cobro en línea: por ahora no se '
                'cobra de verdad.',
                style: TextStyle(color: context.colores.aviso),
              ),
            ),
          if (cuenta.porPagarSinCargar)
            const TextoVacio(
              'No se pudo cargar lo que tienes por pagar. Desliza para '
              'reintentar.',
            )
          else if (cuenta.porPagar.isEmpty)
            const TextoVacio('Estás al corriente.')
          else
            for (final o in cuenta.porPagar)
              PorPagarTile(
                o,
                // Cada orden con su estado (la casilla no pasa de una a otra).
                key: ValueKey(o.id),
                pagoEnLinea: cuenta.pagoEnLinea,
                pagoAutomatico: cuenta.domiciliarAlPagar && !esCitas,
              ),
          // Comprar un plan (en citas, un bono): crea la orden y queda en "Por pagar".
          const ComprarPlanesSeccion(),
          // El corte de cada plan: qué incluía, cómo lo usó y lo que le queda.
          const TituloSeccion('Mis planes'),
          const CortePlanesSeccion(),
          // Si el negocio lo ofrece; en citas no hay nada que se renueve (ADR 0130).
          if (cuenta.pagoAutomatico && !esCitas) ...[
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
          const TituloSeccion('Historial de pagos'),
          const HistorialComprasSeccion(),
          // Lo que compró en recepción (ADR 0145); sin compras, no aparece.
          const ComprasMostradorSeccion(),
        ],
      ),
    );
  }
}
