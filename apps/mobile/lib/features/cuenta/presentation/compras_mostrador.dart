import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/network/participante_en_uso.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../data/compras_mostrador.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_widgets.dart';

/// «Compras en mostrador» (como en la web, ADR 0145): lo que compró en recepción, de
/// lo más reciente a lo más viejo, de 10 en 10, con lo que llevó, cuándo, dónde y
/// cuánto pagó. Las anuladas se ven tachadas. Si nunca compró a su nombre, la
/// sección no aparece.
class ComprasMostradorSeccion extends ConsumerStatefulWidget {
  const ComprasMostradorSeccion({super.key});

  @override
  ConsumerState<ComprasMostradorSeccion> createState() =>
      _ComprasMostradorSeccionState();
}

class _ComprasMostradorSeccionState
    extends ConsumerState<ComprasMostradorSeccion> {
  final _compras = <CompraMostrador>[];
  var _pagina = 0;
  var _hayMas = false;
  var _cargando = false;
  var _error = false;

  @override
  void initState() {
    super.initState();
    _cargar();
    // De otra persona (familias): sus propias compras, desde la primera página.
    ref.listenManual(participanteEnUsoProvider, (_, _) => _reiniciar());
  }

  void _reiniciar() {
    if (_cargando) {
      return;
    }
    setState(() {
      _compras.clear();
      _pagina = 0;
      _hayMas = false;
    });
    _cargar();
  }

  Future<void> _cargar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null || _cargando) {
      return;
    }
    setState(() {
      _cargando = true;
      _error = false;
    });
    try {
      final pagina = await repo.comprasMostrador(pagina: _pagina + 1);
      if (!mounted) {
        return;
      }
      setState(() {
        _compras.addAll(pagina.compras);
        _pagina = pagina.pagina;
        _hayMas = pagina.hayMas;
      });
    } on DioException {
      if (mounted) {
        setState(() => _error = true);
      }
    } finally {
      if (mounted) {
        setState(() => _cargando = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    // Sin compras (ya cargado), no aparece.
    if (_compras.isEmpty && !_cargando && !_error) {
      return const SizedBox.shrink();
    }
    final desfase = ref.watch(sesionProvider)?.desfaseNegocioMin;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const TituloSeccion('Compras en mostrador'),
        if (_compras.isEmpty)
          _cargando
              ? const TextoVacio('Cargando…')
              : _Reintentar(onTap: _cargar)
        else
          Card(
            key: const Key('compras-mostrador'),
            child: Column(
              children: [
                for (final c in _compras)
                  ListTile(
                    key: ValueKey('compra-${c.id}'),
                    title: Text(c.resumen.isEmpty ? '—' : c.resumen),
                    subtitle: Text(c.detalle(desfaseMin: desfase)),
                    trailing: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          Formato.dinero(c.pagadoMinor, c.moneda),
                          style: TextStyle(
                            fontWeight: FontWeight.w600,
                            decoration: c.anulada
                                ? TextDecoration.lineThrough
                                : null,
                          ),
                        ),
                        if (c.anulada)
                          Text(
                            'Anulada',
                            style: TextStyle(
                              fontSize: 12,
                              color: context.colores.textoSuave,
                            ),
                          )
                        else if (c.propinaMinor > 0)
                          Text(
                            'Con ${Formato.dinero(c.propinaMinor, c.moneda)} de propina',
                            style: TextStyle(
                              fontSize: 12,
                              color: context.colores.textoSuave,
                            ),
                          ),
                      ],
                    ),
                  ),
                if (_error)
                  _Reintentar(onTap: _cargar)
                else if (_hayMas)
                  TextButton(
                    onPressed: _cargando ? null : _cargar,
                    child: Text(_cargando ? 'Cargando…' : 'Ver más'),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

class _Reintentar extends StatelessWidget {
  const _Reintentar({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      const Expanded(
        child: TextoVacio('No se pudieron cargar tus compras en mostrador.'),
      ),
      TextButton(onPressed: onTap, child: const Text('Reintentar')),
    ],
  );
}
