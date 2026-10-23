import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/caja_models.dart';
import '../data/caja_repository.dart';

/// Abre el retiro o el ingreso de efectivo de [turnoId]; devuelve el turno con el
/// movimiento registrado (null si se cerró sin registrar).
Future<TurnoCaja?> mostrarMovimientoCaja(
  BuildContext context, {
  required String turnoId,
  required String tipo,
}) => showModalBottomSheet<TurnoCaja>(
  context: context,
  isScrollControlled: true,
  showDragHandle: true,
  builder: (_) => MovimientoCajaSheet(turnoId: turnoId, tipo: tipo),
);

/// Abre el conteo de [turnoId]; devuelve lo que dejó (null si no se registró).
Future<ResultadoConteo?> mostrarConteoCaja(
  BuildContext context, {
  required String turnoId,
}) => showModalBottomSheet<ResultadoConteo>(
  context: context,
  isScrollControlled: true,
  showDragHandle: true,
  builder: (_) => ConteoCajaSheet(turnoId: turnoId),
);

/// Efectivo que sale o entra sin ser una venta (un depósito al banco, más
/// cambio), con su motivo. Reintentarlo igual (sin red) no lo registra dos veces.
class MovimientoCajaSheet extends ConsumerStatefulWidget {
  const MovimientoCajaSheet({
    super.key,
    required this.turnoId,
    required this.tipo,
  });

  final String turnoId;

  /// `retiro` o `ingreso`.
  final String tipo;

  @override
  ConsumerState<MovimientoCajaSheet> createState() =>
      _MovimientoCajaSheetState();
}

class _MovimientoCajaSheetState extends ConsumerState<MovimientoCajaSheet> {
  final _monto = TextEditingController();
  final _motivo = TextEditingController();
  bool _guardando = false;
  String? _error;

  bool get _esIngreso => widget.tipo == 'ingreso';

  @override
  void dispose() {
    _monto.dispose();
    _motivo.dispose();
    super.dispose();
  }

  Future<void> _registrar() async {
    final repo = ref.read(cajaRepositoryProvider);
    final monto = Formato.aMinor(_monto.text);
    if (monto == null || monto < 1) {
      setState(() => _error = 'Escribe un monto válido.');
      return;
    }
    if (_motivo.text.trim().length < 3) {
      setState(() => _error = 'Escribe el motivo.');
      return;
    }
    if (repo == null) {
      return;
    }
    setState(() {
      _guardando = true;
      _error = null;
    });
    try {
      final turno = await repo.moverEfectivo(
        widget.turnoId,
        tipo: widget.tipo,
        montoMinor: monto,
        motivo: _motivo.text,
      );
      if (mounted) {
        Navigator.of(context).pop(turno);
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _guardando = false;
          _error = mensajeDeError(e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Padding(
      padding: EdgeInsets.fromLTRB(
        16,
        0,
        16,
        16 + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              _esIngreso ? 'Ingreso de efectivo' : 'Retiro de efectivo',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            Text(
              _esIngreso
                  ? 'Efectivo que entra sin ser una venta: más cambio.'
                  : 'Efectivo que sale de la caja sin ser una devolución: un '
                        'depósito al banco, un pago a un proveedor.',
              style: TextStyle(color: context.colores.textoSuave),
            ),
            const SizedBox(height: 16),
            TextField(
              key: const Key('monto-movimiento'),
              controller: _monto,
              enabled: !_guardando,
              keyboardType: const TextInputType.numberWithOptions(
                decimal: true,
              ),
              decoration: const InputDecoration(labelText: 'Monto'),
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('motivo-movimiento'),
              controller: _motivo,
              enabled: !_guardando,
              maxLength: 300,
              decoration: InputDecoration(
                labelText: 'Motivo',
                hintText: _esIngreso
                    ? 'Ej. Más cambio'
                    : 'Ej. Depósito al banco',
              ),
            ),
            if (_error != null) ...[
              const SizedBox(height: 4),
              Text(
                _error!,
                key: const Key('error-movimiento'),
                style: TextStyle(color: context.colores.error),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('registrar-movimiento'),
              onPressed: _guardando ? null : _registrar,
              child: Text(_guardando ? 'Registrando…' : 'Registrar'),
            ),
          ],
        ),
      ),
    ),
  );
}

/// El conteo a ciegas: lo que hay en la caja (el efectivo se cuenta) y los totales
/// del reporte de la terminal y de las transferencias. Aquí no se ve lo esperado;
/// el resultado (y la diferencia, si la hay) llega después de registrarlo, una
/// sola vez.
class ConteoCajaSheet extends ConsumerStatefulWidget {
  const ConteoCajaSheet({super.key, required this.turnoId});

  final String turnoId;

  @override
  ConsumerState<ConteoCajaSheet> createState() => _ConteoCajaSheetState();
}

class _ConteoCajaSheetState extends ConsumerState<ConteoCajaSheet> {
  final _contado = {
    for (final m in MetodosCaja.todos) m: TextEditingController(),
  };
  bool _guardando = false;
  String? _error;

  @override
  void dispose() {
    for (final c in _contado.values) {
      c.dispose();
    }
    super.dispose();
  }

  /// Lo contado de cada forma en centavos, o null (con el error) si algo no se
  /// entiende. Vacío cuenta cero; fuera del efectivo el reporte puede salir
  /// negativo (una devolución por la terminal en un día sin ventas con tarjeta).
  Map<String, int>? _montos() {
    final montos = <String, int>{};
    for (final m in MetodosCaja.todos) {
      final texto = _contado[m]!.text.trim();
      final minor = texto.isEmpty
          ? 0
          : Formato.aMinor(texto, negativo: m != MetodosCaja.efectivo);
      if (minor == null) {
        setState(
          () => _error = '${MetodosCaja.nombre(m)}: escribe un monto válido.',
        );
        return null;
      }
      montos[m] = minor;
    }
    return montos;
  }

  Future<void> _registrar() async {
    final montos = _montos();
    final repo = ref.read(cajaRepositoryProvider);
    if (montos == null || repo == null) {
      return;
    }
    final confirmado = await showDialog<bool>(
      context: context,
      builder: (dialogo) => AlertDialog(
        title: const Text('¿Registrar el conteo?'),
        content: const Text(
          'Ya no se podrá cambiar y la caja dejará de recibir cobros.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogo).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            key: const Key('confirmar-conteo'),
            onPressed: () => Navigator.of(dialogo).pop(true),
            child: const Text('Registrar conteo'),
          ),
        ],
      ),
    );
    if (confirmado != true || !mounted) {
      return;
    }
    setState(() {
      _guardando = true;
      _error = null;
    });
    try {
      final resultado = await repo.contar(widget.turnoId, montos);
      if (mounted) {
        Navigator.of(context).pop(resultado);
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _guardando = false;
          _error = mensajeDeError(e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Padding(
      padding: EdgeInsets.fromLTRB(
        16,
        0,
        16,
        16 + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Contar la caja',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            Text(
              'Cuenta el efectivo que hay en la caja y escribe los totales del '
              'reporte de la terminal y de transferencias. El conteo se registra '
              'una sola vez.',
              style: TextStyle(color: context.colores.textoSuave),
            ),
            for (final m in MetodosCaja.todos) ...[
              const SizedBox(height: 12),
              TextField(
                key: Key('contado-$m'),
                controller: _contado[m],
                enabled: !_guardando,
                keyboardType: TextInputType.numberWithOptions(
                  decimal: true,
                  signed: m != MetodosCaja.efectivo,
                ),
                decoration: InputDecoration(
                  labelText: MetodosCaja.nombre(m),
                  hintText: '0',
                ),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!,
                key: const Key('error-conteo'),
                style: TextStyle(color: context.colores.error),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('registrar-conteo'),
              onPressed: _guardando ? null : _registrar,
              child: Text(_guardando ? 'Registrando…' : 'Registrar conteo'),
            ),
          ],
        ),
      ),
    ),
  );
}
