import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/presentation/cuenta_widgets.dart';
import '../data/ventas_models.dart';
import '../data/ventas_repository.dart';
import 'sede_de_cobro.dart';

/// «Por cobrar» en la app del equipo, como en la web: lo pendiente de pago de sus
/// sedes, de 20 en 20, y registrar en caja el pago de cada orden (con la forma de
/// pago y la sede donde se cobra). Se confirma antes, con lo que se cobra.
class PorCobrarScreen extends ConsumerStatefulWidget {
  const PorCobrarScreen({super.key});

  @override
  ConsumerState<PorCobrarScreen> createState() => _PorCobrarScreenState();
}

class _PorCobrarScreenState extends ConsumerState<PorCobrarScreen> {
  final _ordenes = <OrdenEquipo>[];
  var _pagina = 0;
  var _hayMas = false;
  var _cargando = true;
  var _errorCarga = false;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar({bool masAdelante = false}) async {
    final repo = ref.read(ventasRepositoryProvider);
    if (repo == null) {
      return;
    }
    setState(() {
      _cargando = true;
      _errorCarga = false;
    });
    try {
      final pagina = await repo.pendientes(
        pagina: masAdelante ? _pagina + 1 : 1,
      );
      if (!mounted) {
        return;
      }
      setState(() {
        if (!masAdelante) {
          _ordenes.clear();
        }
        // Una orden que ya venía en la página anterior no se repite.
        final vistas = _ordenes.map((o) => o.id).toSet();
        _ordenes.addAll(pagina.ordenes.where((o) => !vistas.contains(o.id)));
        _pagina = pagina.pagina;
        _hayMas = pagina.hayMas;
        _cargando = false;
      });
    } on DioException {
      if (mounted) {
        setState(() {
          _cargando = false;
          _errorCarga = true;
        });
      }
    }
  }

  Future<void> _cobrar(OrdenEquipo o) async {
    final mensajero = ScaffoldMessenger.of(context);
    final cobrada = await showModalBottomSheet<OrdenEquipo>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => CobrarOrdenSheet(orden: o),
    );
    if (cobrada == null || !mounted) {
      return;
    }
    setState(() => _ordenes.removeWhere((x) => x.id == o.id));
    mensajero.showSnackBar(
      SnackBar(
        content: Text(
          'Pago registrado: ${cobrada.estado == 'pagada' && cobrada.totalMinor > 0 ? cobrada.total : o.total} de ${o.comprador ?? '—'}.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final puedeCobrar = puedeCobrarOrdenes(ref.watch(sesionProvider));
    return Scaffold(
      appBar: AppBar(title: const Text('Por cobrar')),
      body: _errorCarga && _ordenes.isEmpty
          ? ErrorDeCarga(
              mensaje: 'No se pudo cargar lo pendiente de cobro.',
              onReintentar: _cargar,
            )
          : _cargando && _ordenes.isEmpty
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _cargar,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
                children: [
                  if (_ordenes.isEmpty)
                    const TextoVacio('Todo al día: nada por cobrar.'),
                  for (final o in _ordenes)
                    Card(
                      key: ValueKey('orden-${o.id}'),
                      child: ListTile(
                        title: Text(o.comprador ?? '—'),
                        subtitle: Text(o.concepto),
                        trailing: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            Text(
                              o.total,
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            if (puedeCobrar)
                              Text(
                                'Cobrar',
                                style: TextStyle(
                                  color: context.colores.acento,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                          ],
                        ),
                        onTap: puedeCobrar ? () => _cobrar(o) : null,
                      ),
                    ),
                  if (_hayMas)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: OutlinedButton(
                        onPressed: _cargando
                            ? null
                            : () => _cargar(masAdelante: true),
                        child: Text(_cargando ? 'Cargando…' : 'Ver más'),
                      ),
                    ),
                  if (_errorCarga && _ordenes.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(
                        'No se pudieron cargar más.',
                        style: TextStyle(color: context.colores.error),
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}

/// Registrar en caja el pago de una orden pendiente: la forma de pago, la sede
/// donde se cobra y una referencia opcional. Todo en efectivo o por transferencia
/// aplica el precio de contado del negocio (ADR 0191), y se dice antes. Se confirma
/// con lo que se cobra; mientras va, el botón no cobra otra vez. Cierra con la orden
/// cobrada.
class CobrarOrdenSheet extends ConsumerStatefulWidget {
  const CobrarOrdenSheet({super.key, required this.orden});

  final OrdenEquipo orden;

  @override
  ConsumerState<CobrarOrdenSheet> createState() => _CobrarOrdenSheetState();
}

class _CobrarOrdenSheetState extends ConsumerState<CobrarOrdenSheet> {
  var _forma = FormasCobro.efectivo;
  String? _sede;
  final _referencia = TextEditingController();
  var _cobrando = false;
  String? _error;

  @override
  void dispose() {
    _referencia.dispose();
    super.dispose();
  }

  int get _porcentaje => ref.read(sesionProvider)?.descuentoContadoPct ?? 0;

  /// Lo que se cobra con la forma elegida.
  int get _aCobrar =>
      totalDeContado(
        totalMinor: widget.orden.totalMinor,
        porcentaje: _porcentaje,
        forma: _forma,
        yaDescontadoMinor: widget.orden.descuentoContadoMinor,
      ) ??
      widget.orden.totalMinor;

  Future<void> _cobrar() async {
    final repo = ref.read(ventasRepositoryProvider);
    final o = widget.orden;
    if (repo == null || _cobrando || _sede == null) {
      return;
    }
    final monto = Formato.dinero(_aCobrar, o.moneda);
    final seguir = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        content: Text(
          '¿Registrar el pago de $monto de ${o.comprador ?? '—'} '
          '(${FormasCobro.nombre(_forma).toLowerCase()})? La orden queda pagada y '
          'entra en la caja de la sede.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(contexto).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(contexto).pop(true),
            child: Text('Cobrar $monto'),
          ),
        ],
      ),
    );
    if (seguir != true || !mounted) {
      return;
    }
    setState(() {
      _cobrando = true;
      _error = null;
    });
    try {
      final cobrada = await repo.cobrar(
        o.id,
        forma: _forma,
        sucursalId: _sede,
        referencia: _referencia.text,
      );
      if (mounted) {
        Navigator.of(context).pop(cobrada);
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _error = mensajeDeError(e, porDefecto: 'No se pudo cobrar.');
          _cobrando = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final o = widget.orden;
    final conContado = _aCobrar != o.totalMinor;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          0,
          20,
          16 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Registrar pago',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 4),
              Text('${o.comprador ?? '—'} · ${o.concepto}'),
              const SizedBox(height: 8),
              Text(
                Formato.dinero(_aCobrar, o.moneda),
                key: const Key('total-a-cobrar'),
                style: const TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w800,
                ),
              ),
              if (conContado)
                Text(
                  'Con el ${_porcentaje.clamp(0, 30)} % de descuento por pagar de '
                  'contado (antes ${o.total}).',
                  key: const Key('descuento-contado'),
                  style: TextStyle(color: context.colores.textoSuave),
                )
              else if (_porcentaje > 0 && o.descuentoContadoMinor == 0)
                Text(
                  'Todo en efectivo o por transferencia tiene '
                  '${_porcentaje.clamp(0, 30)} % de descuento.',
                  style: TextStyle(color: context.colores.textoSuave),
                ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                key: const Key('forma-de-pago'),
                isExpanded: true,
                initialValue: _forma,
                decoration: const InputDecoration(labelText: 'Forma de pago'),
                items: [
                  for (final f in FormasCobro.todas)
                    DropdownMenuItem(
                      value: f,
                      child: Text(FormasCobro.nombre(f)),
                    ),
                ],
                onChanged: _cobrando
                    ? null
                    : (v) => setState(() => _forma = v ?? _forma),
              ),
              const SizedBox(height: 12),
              SedeDeCobro(
                habilitado: !_cobrando,
                alElegir: (s) => setState(() => _sede = s),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _referencia,
                enabled: !_cobrando,
                maxLength: 255,
                decoration: const InputDecoration(
                  labelText: 'Referencia (opcional)',
                  helperText: 'Folio de la terminal o de la transferencia.',
                ),
              ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    _error!,
                    style: TextStyle(color: context.colores.error),
                  ),
                ),
              const SizedBox(height: 12),
              FilledButton(
                key: const Key('cobrar-orden'),
                onPressed: _cobrando || _sede == null ? null : _cobrar,
                child: Text(
                  _cobrando
                      ? 'Cobrando…'
                      : 'Cobrar ${Formato.dinero(_aCobrar, o.moneda)}',
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
