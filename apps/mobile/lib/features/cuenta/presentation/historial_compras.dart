import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/network/participante_en_uso.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_widgets.dart';

/// Su historial de compras y cobros (como en la web): lo que pagó o se canceló, de
/// lo más reciente a lo más viejo, de 10 en 10. Lo que debe se ve arriba, completo.
/// Cada cobro dice qué se pagó; si es una cita, el servicio, con quién y cuándo.
class HistorialComprasSeccion extends ConsumerStatefulWidget {
  const HistorialComprasSeccion({super.key});

  @override
  ConsumerState<HistorialComprasSeccion> createState() =>
      _HistorialComprasSeccionState();
}

class _HistorialComprasSeccionState
    extends ConsumerState<HistorialComprasSeccion> {
  final _ordenes = <OrdenPorPagar>[];
  var _pagina = 0;
  var _hayMas = true;
  var _cargando = false;
  var _error = false;

  @override
  void initState() {
    super.initState();
    _cargar();
    // De otra persona (familias): su propio historial, desde la primera página.
    ref.listenManual(participanteEnUsoProvider, (_, _) {
      setState(() {
        _ordenes.clear();
        _pagina = 0;
        _hayMas = true;
      });
      _cargar();
    });
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
      final pagina = await repo.historialCompras(pagina: _pagina + 1);
      if (!mounted) {
        return;
      }
      setState(() {
        _ordenes.addAll(pagina.ordenes);
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
    if (_ordenes.isEmpty) {
      if (_cargando) {
        return const TextoVacio('Cargando…');
      }
      if (_error) {
        return _Reintentar(onTap: _cargar);
      }
      return const TextoVacio('Aún no hay pagos.');
    }
    return Card(
      key: const Key('historial-compras'),
      child: Column(
        children: [
          for (final o in _ordenes)
            ListTile(
              title: Text(o.concepto),
              subtitle: Text(
                // La cita (con quién y cuándo) o el día de la compra.
                o.detalle ?? (o.fecha == null ? '' : Formato.dia(o.fecha!)),
              ),
              trailing: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    Formato.dinero(o.totalMinor, o.moneda),
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  Text(
                    o.estadoTexto,
                    style: TextStyle(
                      fontSize: 12,
                      color: o.estado == 'pagada'
                          ? context.colores.exito
                          : context.colores.textoSuave,
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
    );
  }
}

class _Reintentar extends StatelessWidget {
  const _Reintentar({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      const Expanded(child: TextoVacio('No se pudo cargar tu historial.')),
      TextButton(onPressed: onTap, child: const Text('Reintentar')),
    ],
  );
}
