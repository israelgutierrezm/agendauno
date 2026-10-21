import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/network/participante_en_uso.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import '../data/presupuestos.dart';
import 'agendar_cita_sheet.dart';
import 'cuenta_widgets.dart';

/// Abre una página en el navegador del teléfono (la del pago); se puede cambiar en
/// las pruebas.
typedef AbrirEnNavegador = Future<bool> Function(Uri url);

Future<bool> _abrirEnNavegador(Uri url) =>
    launchUrl(url, mode: LaunchMode.externalApplication);

/// «Mis presupuestos» (negocios de citas, como en la web): los que le mandó el
/// negocio, para verlos, aceptarlos o rechazarlos, pagarlos en línea y agendar sus
/// servicios. El que espera respuesta aparece abierto. Sin presupuestos (o si no
/// cargan), la sección no aparece.
class MisPresupuestosSeccion extends ConsumerStatefulWidget {
  const MisPresupuestosSeccion({super.key, this.abrir = _abrirEnNavegador});

  final AbrirEnNavegador abrir;

  @override
  ConsumerState<MisPresupuestosSeccion> createState() =>
      _MisPresupuestosSeccionState();
}

class _MisPresupuestosSeccionState
    extends ConsumerState<MisPresupuestosSeccion> {
  var _lista = <PresupuestoCliente>[];
  String? _abierto;
  var _cargando = false;

  @override
  void initState() {
    super.initState();
    _cargar();
    // Cada vez que la cuenta se recarga (al volver de pagar, al deslizar para
    // actualizar), se leen de nuevo.
    ref.listenManual(cuentaProvider, (antes, ahora) {
      if (antes?.value != null && ahora.hasValue && !ahora.isLoading) {
        _cargar();
      }
    });
  }

  Future<void> _cargar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null || _cargando) {
      return;
    }
    _cargando = true;
    final List<PresupuestoCliente> lista;
    try {
      lista = await repo.presupuestos();
    } on DioException {
      // Si no cargan, se queda lo que ya se veía (al entrar, nada: la sección no
      // aparece).
      return;
    } finally {
      _cargando = false;
    }
    if (!mounted) {
      return;
    }
    setState(() {
      _lista = lista;
      // El que espera respuesta, abierto.
      _abierto ??= lista.where((p) => p.puedeResponder).firstOrNull?.id;
    });
  }

  void _actualizar(PresupuestoCliente p) => setState(() {
    _lista = [for (final x in _lista) x.id == p.id ? p : x];
  });

  @override
  Widget build(BuildContext context) {
    // Son de quien tiene la cuenta: por una persona a su cargo no se muestran.
    if (_lista.isEmpty || ref.watch(participanteEnUsoProvider) != null) {
      return const SizedBox.shrink();
    }
    return Column(
      key: const Key('mis-presupuestos'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const TituloSeccion('Mis presupuestos'),
        for (final p in _lista)
          Card(
            key: ValueKey('presupuesto-${p.id}'),
            clipBehavior: Clip.antiAlias,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                InkWell(
                  onTap: () =>
                      setState(() => _abierto = _abierto == p.id ? null : p.id),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                p.folio,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                              Text(
                                p.vigencia,
                                style: TextStyle(
                                  fontSize: 13,
                                  color: context.colores.textoSuave,
                                ),
                              ),
                            ],
                          ),
                        ),
                        Text(
                          Formato.dinero(p.totalMinor, p.moneda),
                          style: const TextStyle(fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                  ),
                ),
                if (_abierto == p.id)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        DetallePresupuesto(p),
                        const SizedBox(height: 16),
                        _RespuestaPresupuesto(
                          p,
                          // Con el mismo id: otro presupuesto, otro estado.
                          key: ValueKey('respuesta-${p.id}'),
                          alActualizar: _actualizar,
                          abrir: widget.abrir,
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

/// Los conceptos, los totales con sus impuestos y las notas del negocio.
class DetallePresupuesto extends StatelessWidget {
  const DetallePresupuesto(this.p, {super.key});

  final PresupuestoCliente p;

  @override
  Widget build(BuildContext context) {
    String dinero(int minor) => Formato.dinero(minor, p.moneda);
    final suave = TextStyle(fontSize: 13, color: context.colores.textoSuave);
    Widget total(String etiqueta, String valor, {bool fuerte = false}) =>
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  etiqueta,
                  style: fuerte
                      ? const TextStyle(fontWeight: FontWeight.w600)
                      : null,
                ),
              ),
              Text(
                valor,
                style: fuerte
                    ? const TextStyle(fontWeight: FontWeight.w600)
                    : null,
              ),
            ],
          ),
        );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final l in p.lineas)
          Container(
            padding: const EdgeInsets.symmetric(vertical: 8),
            decoration: BoxDecoration(
              border: Border(
                bottom: BorderSide(color: Theme.of(context).dividerColor),
              ),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(l.texto),
                      if (l.descuentoMinor > 0)
                        Text(
                          'Descuento: −${dinero(l.descuentoMinor)}',
                          style: suave,
                        ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                Text(dinero(l.subtotalMinor)),
              ],
            ),
          ),
        const SizedBox(height: 8),
        total('Subtotal', dinero(p.subtotalMinor)),
        if (p.descuentoMinor > 0)
          total('Descuento', '−${dinero(p.descuentoMinor)}'),
        if (p.impuestosDesglosados)
          total('Impuestos (${p.tasaImpuesto})', dinero(p.impuestoMinor)),
        const Divider(height: 12),
        total('Total', dinero(p.totalMinor), fuerte: true),
        if (!p.impuestosDesglosados && p.tasaImpuesto.isNotEmpty)
          Text(
            'Precios con impuestos incluidos (${p.tasaImpuesto}).',
            textAlign: TextAlign.right,
            style: suave,
          ),
        if (p.notas != null) ...[
          const SizedBox(height: 12),
          Text(p.notas!, style: suave),
        ],
      ],
    );
  }
}

/// Lo que hace con su presupuesto: cómo está, aceptarlo (se vuelve su cobro) o
/// rechazarlo con un motivo, pagarlo en línea y agendar sus servicios.
class _RespuestaPresupuesto extends ConsumerStatefulWidget {
  const _RespuestaPresupuesto(
    this.p, {
    super.key,
    required this.alActualizar,
    required this.abrir,
  });

  final PresupuestoCliente p;
  final ValueChanged<PresupuestoCliente> alActualizar;
  final AbrirEnNavegador abrir;

  @override
  ConsumerState<_RespuestaPresupuesto> createState() =>
      _RespuestaPresupuestoState();
}

enum _Accion { aceptar, rechazar, pagar }

class _RespuestaPresupuestoState extends ConsumerState<_RespuestaPresupuesto> {
  _Accion? _ocupado;
  String? _error;
  var _rechazando = false;
  final _motivo = TextEditingController();

  @override
  void dispose() {
    _motivo.dispose();
    super.dispose();
  }

  Color _tono(SituacionPresupuesto s) => switch (s) {
    SituacionPresupuesto.porResponder => context.colores.aviso,
    SituacionPresupuesto.aceptado ||
    SituacionPresupuesto.pagado => context.colores.exito,
    SituacionPresupuesto.vencido => context.colores.error,
    SituacionPresupuesto.rechazado => context.colores.textoSuave,
  };

  /// Corre [accion] marcando qué se está haciendo y, si falla (o la acción
  /// devuelve un aviso), dice por qué. Si el negocio lo cambió (409) o ya no se
  /// puede responder (422), se lee de nuevo para mostrarlo como está ahora.
  Future<void> _hacer(_Accion cual, Future<String?> Function() accion) async {
    setState(() {
      _ocupado = cual;
      _error = null;
    });
    String? error;
    try {
      error = await accion();
    } on DioException catch (e) {
      final estado = e.response?.statusCode;
      error = estado == 409
          ? 'El negocio cambió este presupuesto. Revisa la versión actual '
                'antes de aceptarlo.'
          : mensajeDeError(e);
      if (estado == 409 || estado == 422) {
        await _releer();
      }
    }
    if (mounted) {
      setState(() {
        _ocupado = null;
        _error = error;
      });
    }
  }

  Future<void> _releer() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    try {
      final actual = await repo.presupuesto(widget.p.id);
      if (mounted) {
        widget.alActualizar(actual);
      }
    } on DioException {
      // Se queda como estaba; el aviso ya dice qué pasó.
    }
  }

  Future<void> _aceptar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final si = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        content: const Text(
          '¿Aceptas este presupuesto? Quedará como tu cobro con el negocio: lo '
          'pagas en línea o en el negocio.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(contexto).pop(false),
            child: const Text('Volver'),
          ),
          TextButton(
            onPressed: () => Navigator.of(contexto).pop(true),
            child: const Text('Aceptar presupuesto'),
          ),
        ],
      ),
    );
    if (si != true || !mounted) {
      return;
    }
    await _hacer(_Accion.aceptar, () async {
      final p = await repo.aceptarPresupuesto(widget.p.id, widget.p.revision);
      widget.alActualizar(p);
      // Su cobro aparece en «Por pagar».
      await ref.read(cuentaProvider.notifier).recargar();
      return null;
    });
  }

  Future<void> _rechazar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final motivo = _motivo.text.trim();
    await _hacer(_Accion.rechazar, () async {
      final p = await repo.rechazarPresupuesto(
        widget.p.id,
        motivo: motivo.isEmpty ? null : motivo,
      );
      if (mounted) {
        setState(() => _rechazando = false);
      }
      widget.alActualizar(p);
      return null;
    });
  }

  Future<void> _pagar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final mensajero = ScaffoldMessenger.of(context);
    await _hacer(_Accion.pagar, () async {
      final checkout = await repo.pagarPresupuesto(widget.p);
      if (checkout is! IrAPagarEnLinea) {
        return 'No se pudo abrir el pago en línea: inténtalo de nuevo.';
      }
      final abierto = await widget.abrir(Uri.parse(checkout.url));
      mensajero.showSnackBar(
        SnackBar(
          content: Text(
            abierto
                ? 'Completa el pago en el navegador; al volver actualizamos '
                      'tu cuenta.'
                : 'No se pudo abrir la página de pago.',
          ),
        ),
      );
      return null;
    });
  }

  void _agendar(({String id, String nombre}) servicio) =>
      showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => AgendarCitaSheet(
          servicioId: servicio.id,
          sucursalId: widget.p.sucursal?.id,
        ),
      );

  @override
  Widget build(BuildContext context) {
    final p = widget.p;
    final ocupado = _ocupado != null;
    final suave = TextStyle(color: context.colores.textoSuave);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        EstadoPunto(
          p.situacion.texto,
          key: const Key('presupuesto-estado'),
          color: _tono(p.situacion),
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _error!,
              key: const Key('presupuesto-error'),
              style: TextStyle(color: context.colores.error),
            ),
          ),
        // Aceptar o rechazar mientras está vigente.
        if (p.puedeResponder && !_rechazando)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                FilledButton(
                  onPressed: ocupado ? null : _aceptar,
                  child: Text(
                    _ocupado == _Accion.aceptar
                        ? 'Aceptando…'
                        : 'Aceptar presupuesto',
                  ),
                ),
                OutlinedButton(
                  onPressed: ocupado
                      ? null
                      : () => setState(() => _rechazando = true),
                  child: const Text('Rechazar'),
                ),
              ],
            ),
          ),
        if (p.puedeResponder && _rechazando) ...[
          const SizedBox(height: 12),
          TextField(
            key: const Key('presupuesto-motivo'),
            controller: _motivo,
            maxLength: 500,
            maxLines: 3,
            minLines: 2,
            decoration: const InputDecoration(
              labelText: '¿Por qué lo rechazas? (opcional)',
            ),
          ),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton(
                style: OutlinedButton.styleFrom(
                  foregroundColor: context.colores.error,
                ),
                onPressed: ocupado ? null : _rechazar,
                child: const Text('Rechazar presupuesto'),
              ),
              TextButton(
                onPressed: ocupado
                    ? null
                    : () => setState(() => _rechazando = false),
                child: const Text('Cancelar'),
              ),
            ],
          ),
        ],
        if (p.vencioSinResponder)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              'Ya pasó su vigencia: pide al negocio uno nuevo si aún te '
              'interesa.',
              style: suave,
            ),
          ),
        // Aceptado: pagarlo en línea (o en el negocio).
        if (p.porPagar)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: p.puedePagar
                ? FilledButton(
                    onPressed: ocupado ? null : _pagar,
                    child: Text(
                      _ocupado == _Accion.pagar
                          ? 'Abriendo el pago…'
                          : 'Pagar en línea',
                    ),
                  )
                : Text('Págalo en el negocio cuando vayas.', style: suave),
          ),
        // Y agendar sus servicios.
        if (p.puedeAgendar) ...[
          const SizedBox(height: 16),
          const Text(
            'Agenda tus citas',
            style: TextStyle(fontWeight: FontWeight.w500),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final s in p.servicios)
                OutlinedButton(
                  key: ValueKey('agendar-${s.id}'),
                  onPressed: () => _agendar(s),
                  child: Text('Agendar ${s.nombre}'),
                ),
            ],
          ),
        ],
      ],
    );
  }
}
