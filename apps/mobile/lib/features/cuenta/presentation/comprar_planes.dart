import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import 'cuenta_screen.dart';
import 'cuenta_widgets.dart';

/// Comprar un plan desde la app (como en la web): cada paquete o membresía con su
/// precio, lo que incluye y cuánto dura. Comprar crea la orden, que aparece arriba en
/// "Por pagar" para pagarla en línea o en recepción. Sin planes, no se muestra.
class ComprarPlanesSeccion extends ConsumerStatefulWidget {
  const ComprarPlanesSeccion({super.key});

  @override
  ConsumerState<ComprarPlanesSeccion> createState() =>
      _ComprarPlanesSeccionState();
}

class _ComprarPlanesSeccionState extends ConsumerState<ComprarPlanesSeccion> {
  String? _comprando;

  Future<void> _comprar(ProductoComprable p) async {
    // Permanencia (B04): se leen y se aceptan las condiciones antes de comprar.
    final condiciones = p.condiciones;
    if (condiciones != null) {
      final acepta = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Condiciones del plan'),
          content: Text(condiciones),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Cancelar'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Acepto y compro'),
            ),
          ],
        ),
      );
      if (acepta != true || !mounted) {
        return;
      }
    }
    setState(() => _comprando = p.id);
    await hacerConAviso(
      context,
      () => ref
          .read(cuentaProvider.notifier)
          .comprar(p.id, aceptaCondiciones: condiciones != null),
      // En citas se compran bonos (ADR 0130).
      exito:
          'Compra creada. Págala en "Por pagar" para activar '
          '${(ref.read(sesionProvider)?.esCitas ?? false) ? 'tu bono' : 'tus créditos'}.',
    );
    if (mounted) {
      setState(() => _comprando = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final productos = ref.watch(productosProvider).value ?? const [];
    if (productos.isEmpty) {
      return const SizedBox.shrink();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const TituloSeccion('Comprar'),
        for (final p in productos)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          p.etiqueta,
                          style: const TextStyle(
                            fontSize: 12,
                            color: TemaAgendaUno.textoSuave,
                          ),
                        ),
                        Text(
                          p.nombre,
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          Formato.dinero(p.precioMinor, p.moneda),
                          style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        // Si no vale en todas las sucursales, en cuáles.
                        for (final dato in [
                          p.descripcion,
                          p.creditosTexto,
                          p.vigenciaTexto,
                          p.venceTexto,
                          // La inscripción que se suma, antes de comprar (B04).
                          p.inscripcionTexto,
                          p.cobertura.texto,
                        ])
                          if (dato != null)
                            Text(
                              dato,
                              style: const TextStyle(
                                fontSize: 12,
                                color: TemaAgendaUno.textoSuave,
                              ),
                            ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  FilledButton(
                    onPressed: _comprando == null ? () => _comprar(p) : null,
                    child: Text(_comprando == p.id ? 'Creando…' : 'Comprar'),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
