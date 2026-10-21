import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_screen.dart';

/// Productos al reservar (ADR 0194), en el detalle de una clase confirmada: lo que
/// el negocio ofrece para recoger ese día y lo que ya apartó. Se paga en el
/// mostrador; si el negocio no ofrece nada, no se muestra.
class ApartadosClase extends ConsumerStatefulWidget {
  const ApartadosClase({super.key, required this.sesionId});

  final String sesionId;

  @override
  ConsumerState<ApartadosClase> createState() => _ApartadosClaseState();
}

class _ApartadosClaseState extends ConsumerState<ApartadosClase> {
  List<ArticuloAlReservar> _articulos = const [];
  List<ApartadoDeClase> _apartados = const [];
  bool _ocupado = false;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    try {
      final r = await repo.articulosDeClase(widget.sesionId);
      if (mounted) {
        setState(() {
          _articulos = r.articulos;
          _apartados = r.apartados;
        });
      }
    } on DioException {
      // Sin productos que ofrecer (o sin conexión): la reserva sigue igual.
    }
  }

  Future<void> _hacer(Future<void> Function() accion, String exito) async {
    setState(() => _ocupado = true);
    await hacerConAviso(context, accion, exito: exito);
    await _cargar();
    if (mounted) {
      setState(() => _ocupado = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final repo = ref.watch(cuentaRepositoryProvider);
    if (repo == null || (_articulos.isEmpty && _apartados.isEmpty)) {
      return const SizedBox.shrink();
    }
    final suave = TextStyle(color: context.colores.textoSuave, fontSize: 13);

    return Padding(
      key: const Key('apartados-clase'),
      padding: const EdgeInsets.only(top: 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Para recoger en tu clase',
            style: TextStyle(fontWeight: FontWeight.w600),
          ),
          Text('Lo pagas en el mostrador al recogerlo.', style: suave),
          for (final a in _apartados)
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${a.cantidad} × ${a.nombre} · ${Formato.dinero(a.totalMinor, a.moneda)}',
                  ),
                ),
                TextButton(
                  key: Key('quitar-apartado-${a.id}'),
                  onPressed: _ocupado
                      ? null
                      : () =>
                            _hacer(() => repo.quitarApartado(a.id), 'Quitado.'),
                  child: const Text('Quitar'),
                ),
              ],
            ),
          for (final art in _articulos)
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${art.nombre} · ${Formato.dinero(art.precioMinor, art.moneda)}',
                  ),
                ),
                TextButton(
                  key: Key('apartar-${art.id}'),
                  onPressed: _ocupado || art.disponibles < 1
                      ? null
                      : () => _hacer(
                          () => repo.apartarArticulo(widget.sesionId, art.id),
                          'Apartado: recógelo en tu clase.',
                        ),
                  child: Text(art.disponibles < 1 ? 'Agotado' : 'Apartar'),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
