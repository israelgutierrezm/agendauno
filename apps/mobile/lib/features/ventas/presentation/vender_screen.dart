import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../agenda/data/agenda_models.dart';
import '../../agenda/presentation/buscar_persona.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../cuenta/presentation/cuenta_widgets.dart';
import '../data/ventas_models.dart';
import '../data/ventas_repository.dart';
import 'sede_de_cobro.dart';

/// Los planes a la venta (sin los archivados).
final planesVentaProvider = FutureProvider.autoDispose<List<PlanVenta>>((
  ref,
) async {
  final repo = ref.watch(ventasRepositoryProvider);
  return repo == null ? const [] : repo.planes();
});

/// Vender un plan (membresía, paquete, bono, clase suelta…) en el mostrador, como la
/// venta rápida de la web: a quién, qué, cómo paga y en qué sede. Se vende y se
/// cobra de una vez (si el cobro no pasa, no queda nada pendiente). Antes de cobrar
/// se dice el total, con la inscripción que le toque, y con permanencia el cliente
/// acepta sus condiciones.
class VenderScreen extends ConsumerStatefulWidget {
  const VenderScreen({super.key});

  @override
  ConsumerState<VenderScreen> createState() => _VenderScreenState();
}

class _VenderScreenState extends ConsumerState<VenderScreen> {
  ClienteAgenda? _persona;
  PlanVenta? _plan;
  var _forma = FormasCobro.efectivo;
  String? _sede;
  var _aceptaCondiciones = false;

  /// La inscripción que se le sumaría (centavos); null mientras se consulta o si no
  /// se pudo (entonces no se cobra: el total que se confirma es el que se cobra).
  int? _inscripcion = 0;
  var _errorInscripcion = false;
  var _pedidoInscripcion = 0;

  var _vendiendo = false;
  String? _error;

  Sesion? get _sesion => ref.read(sesionProvider);

  /// Pregunta cuánto se sumaría de inscripción al elegir persona o plan.
  Future<void> _consultarInscripcion() async {
    final persona = _persona;
    final plan = _plan;
    final repo = ref.read(ventasRepositoryProvider);
    final pedido = ++_pedidoInscripcion;
    if (persona == null ||
        plan == null ||
        !plan.cobraInscripcion ||
        repo == null ||
        (_sesion?.esCitas ?? false)) {
      setState(() {
        _inscripcion = 0;
        _errorInscripcion = false;
      });
      return;
    }
    setState(() {
      _inscripcion = null;
      _errorInscripcion = false;
    });
    try {
      final monto = await repo.inscripcion(persona.id);
      if (mounted && pedido == _pedidoInscripcion) {
        setState(() => _inscripcion = monto);
      }
    } on DioException {
      if (mounted && pedido == _pedidoInscripcion) {
        setState(() => _errorInscripcion = true);
      }
    }
  }

  void _elegirPersona(ClienteAgenda? c) {
    setState(() {
      _persona = c;
      _aceptaCondiciones = false;
      _error = null;
    });
    _consultarInscripcion();
  }

  void _elegirPlan(PlanVenta p) {
    setState(() {
      _plan = p;
      _aceptaCondiciones = false;
      _error = null;
    });
    _consultarInscripcion();
  }

  int? get _total {
    final plan = _plan;
    final inscripcion = _inscripcion;
    return plan == null || inscripcion == null
        ? null
        : plan.precioMinor + inscripcion;
  }

  bool get _listo =>
      _persona != null &&
      _plan != null &&
      _total != null &&
      _sede != null &&
      (!_plan!.pideCondiciones || _aceptaCondiciones) &&
      !_vendiendo;

  Future<void> _vender() async {
    final persona = _persona;
    final plan = _plan;
    final total = _total;
    final repo = ref.read(ventasRepositoryProvider);
    if (!_listo || persona == null || plan == null || total == null) {
      return;
    }
    if (repo == null) {
      return;
    }
    final monto = Formato.dinero(total, plan.moneda);
    final seguir = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        content: Text(
          '¿Vender ${plan.nombre} a ${persona.nombre} por $monto '
          '(${FormasCobro.nombre(_forma).toLowerCase()})? Se cobra ahora y entra '
          'en la caja de la sede.',
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
      _vendiendo = true;
      _error = null;
    });
    final mensajero = ScaffoldMessenger.of(context);
    try {
      await repo.vender(
        personaId: persona.id,
        planId: plan.id,
        forma: _forma,
        sucursalId: _sede,
        aceptaCondiciones: plan.pideCondiciones && _aceptaCondiciones,
      );
      if (!mounted) {
        return;
      }
      // Ya se cobró: no queda listo para cobrar otra vez lo mismo.
      setState(() {
        _persona = null;
        _plan = null;
        _inscripcion = 0;
        _aceptaCondiciones = false;
        _vendiendo = false;
      });
      mensajero.showSnackBar(
        SnackBar(
          content: Text('Listo: ${persona.nombre} ya tiene ${plan.nombre}.'),
        ),
      );
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _error = mensajeDeError(e, porDefecto: 'No se pudo vender.');
          _vendiendo = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    final esCitas = sesion?.esCitas ?? false;
    final miembro = (sesion?.terminologia ?? const Terminologia()).miembro
        .toLowerCase();
    final planes = ref.watch(planesVentaProvider);
    final persona = _persona;
    final plan = _plan;
    final total = _total;
    return Scaffold(
      appBar: AppBar(title: const Text('Vender')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          TituloSeccion('A quién'),
          if (persona == null)
            BuscarPersona(
              habilitado: !_vendiendo,
              etiqueta: 'Buscar $miembro por nombre, correo o celular',
              onElegir: _elegirPersona,
            )
          else
            ListTile(
              key: const Key('comprador'),
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.person_outline),
              title: Text(persona.nombre),
              subtitle: persona.detalle == null ? null : Text(persona.detalle!),
              trailing: TextButton(
                onPressed: _vendiendo ? null : () => _elegirPersona(null),
                child: const Text('Cambiar'),
              ),
            ),
          TituloSeccion(esCitas ? 'Qué bono' : 'Qué plan'),
          planes.when(
            loading: () => const LinearProgressIndicator(),
            error: (_, _) => ErrorDeCarga(
              mensaje: 'No se pudieron cargar los planes.',
              onReintentar: () => ref.invalidate(planesVentaProvider),
            ),
            data: (lista) => lista.isEmpty
                ? const TextoVacio('No hay planes a la venta.')
                : Column(
                    children: [
                      for (final p in lista)
                        Card(
                          key: ValueKey('plan-${p.id}'),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                            side: BorderSide(
                              color: plan?.id == p.id
                                  ? context.colores.acento
                                  : context.colores.borde,
                              width: plan?.id == p.id ? 2 : 1,
                            ),
                          ),
                          child: ListTile(
                            selected: plan?.id == p.id,
                            title: Text(p.nombre),
                            subtitle: Text(p.tipoTexto(esCitas: esCitas)),
                            trailing: Text(
                              p.precio,
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            onTap: _vendiendo ? null : () => _elegirPlan(p),
                          ),
                        ),
                    ],
                  ),
          ),
          TituloSeccion('Cómo paga'),
          DropdownButtonFormField<String>(
            key: const Key('forma-de-venta'),
            isExpanded: true,
            initialValue: _forma,
            decoration: const InputDecoration(labelText: 'Forma de pago'),
            items: [
              for (final f in FormasCobro.todas)
                DropdownMenuItem(value: f, child: Text(FormasCobro.nombre(f))),
            ],
            onChanged: _vendiendo
                ? null
                : (v) => setState(() => _forma = v ?? _forma),
          ),
          const SizedBox(height: 12),
          SedeDeCobro(
            habilitado: !_vendiendo,
            alElegir: (s) => setState(() => _sede = s),
          ),
          if (plan != null) ...[
            const SizedBox(height: 16),
            if (_inscripcion == null && !_errorInscripcion)
              const Text('Revisando si le toca inscripción…')
            else if (_errorInscripcion)
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'No se pudo saber si le toca inscripción.',
                      style: TextStyle(color: context.colores.error),
                    ),
                  ),
                  TextButton(
                    onPressed: _consultarInscripcion,
                    child: const Text('Reintentar'),
                  ),
                ],
              )
            else if ((_inscripcion ?? 0) > 0)
              Text(
                'Incluye la inscripción: '
                '${Formato.dinero(_inscripcion!, plan.moneda)}.',
                key: const Key('inscripcion'),
              ),
            if (plan.pideCondiciones)
              CheckboxListTile(
                key: const Key('acepta-condiciones'),
                contentPadding: EdgeInsets.zero,
                value: _aceptaCondiciones,
                onChanged: _vendiendo
                    ? null
                    : (v) => setState(() => _aceptaCondiciones = v ?? false),
                title: Text(
                  'El $miembro acepta las condiciones del plan '
                  '(permanencia de ${plan.permanenciaMeses} '
                  '${plan.permanenciaMeses == 1 ? 'mes' : 'meses'}).',
                ),
                subtitle: plan.condiciones == null
                    ? null
                    : Text(plan.condiciones!),
              ),
          ],
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                _error!,
                style: TextStyle(color: context.colores.error),
              ),
            ),
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('vender'),
            onPressed: _listo ? _vender : null,
            child: Text(
              _vendiendo
                  ? 'Cobrando…'
                  : total == null || plan == null
                  ? 'Cobrar'
                  : 'Cobrar ${Formato.dinero(total, plan.moneda)}',
            ),
          ),
        ],
      ),
    );
  }
}
