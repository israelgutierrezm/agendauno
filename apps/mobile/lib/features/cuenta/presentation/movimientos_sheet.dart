import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';

/// Movimientos de créditos de un plan: por qué cambió su saldo ("Asistencia",
/// "Cancelación tardía", "Créditos vencidos"…), de qué clase y con qué saldo quedó.
/// Por páginas: una membresía de años junta cientos.
class MovimientosSheet extends ConsumerStatefulWidget {
  const MovimientosSheet({required this.derechoId, super.key});

  final String derechoId;

  @override
  ConsumerState<MovimientosSheet> createState() => _MovimientosSheetState();
}

class _MovimientosSheetState extends ConsumerState<MovimientosSheet> {
  final List<MovimientoCredito> _lista = [];
  int _pagina = 0;
  int _ultima = 1;
  int _total = 0;
  bool _cargando = true;
  bool _error = false;

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
    setState(() {
      _cargando = true;
      _error = false;
    });
    try {
      final r = await repo.movimientos(widget.derechoId, pagina: _pagina + 1);
      if (!mounted) {
        return;
      }
      setState(() {
        _lista.addAll(r.lista);
        _pagina = r.pagina;
        _ultima = r.ultimaPagina;
        _total = r.total;
      });
    } catch (_) {
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
    final hayMas = _pagina < _ultima;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Movimientos',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 8),
            if (_error && _lista.isEmpty)
              const Text('No se pudieron cargar los movimientos.')
            else if (_cargando && _lista.isEmpty)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_lista.isEmpty)
              Text(
                'Aún no hay movimientos.',
                style: TextStyle(color: context.colores.textoSuave),
              )
            else
              Flexible(
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: _lista.length + (hayMas ? 1 : 0),
                  separatorBuilder: (_, _) => const Divider(height: 1),
                  itemBuilder: (_, i) => i < _lista.length
                      ? _Movimiento(_lista[i])
                      : TextButton(
                          onPressed: _cargando ? null : _cargar,
                          child: Text(
                            _cargando
                                ? 'Cargando…'
                                : 'Ver más (${_total - _lista.length})',
                          ),
                        ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _Movimiento extends StatelessWidget {
  const _Movimiento(this.m);

  final MovimientoCredito m;

  @override
  Widget build(BuildContext context) {
    final detalle = m.clase != null
        ? '${m.clase} · ${Formato.fechaHora(m.claseIniciaEn)}'
        : Formato.fechaHora(m.fecha);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  m.concepto,
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                Text(
                  detalle,
                  style: TextStyle(color: context.colores.textoSuave),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                MovimientoCredito.creditos(m.unidades),
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              Text(
                'Saldo ${MovimientoCredito.creditos(m.saldoPosterior, conSigno: false)}',
                style: TextStyle(
                  color: context.colores.textoSuave,
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
