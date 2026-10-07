import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
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
    setState(() => _comprando = p.id);
    await hacerConAviso(
      context,
      () => ref.read(cuentaProvider.notifier).comprar(p.id),
      exito: 'Compra creada. Págala en "Por pagar" para activar tus créditos.',
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
                          p.tipoTexto,
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
                        for (final dato in [p.creditosTexto, p.vigenciaTexto])
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
