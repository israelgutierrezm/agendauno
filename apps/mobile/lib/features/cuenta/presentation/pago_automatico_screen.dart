import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_screen.dart' show hacerConAviso;

/// "Pago automático": sus membresías que se renuevan, cuáles se cobran solas y con
/// qué tarjeta. La tarjeta se autoriza en la página de la pasarela (en el
/// navegador); al volver a la app se actualiza.
class PagoAutomaticoScreen extends ConsumerStatefulWidget {
  const PagoAutomaticoScreen({super.key});

  @override
  ConsumerState<PagoAutomaticoScreen> createState() =>
      _PagoAutomaticoScreenState();
}

class _PagoAutomaticoScreenState extends ConsumerState<PagoAutomaticoScreen> {
  // Al volver del navegador (tarjeta autorizada) se recarga.
  late final AppLifecycleListener _ciclo = AppLifecycleListener(
    onResume: _cargar,
  );

  PagoAutomatico? _datos;
  String? _error;
  String? _accionando;

  @override
  void initState() {
    super.initState();
    _ciclo;
    _cargar();
  }

  @override
  void dispose() {
    _ciclo.dispose();
    super.dispose();
  }

  Future<void> _cargar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    try {
      final datos = await repo.pagoAutomatico();
      if (mounted) {
        setState(() {
          _datos = datos;
          _error = null;
        });
      }
    } on DioException {
      if (mounted) {
        setState(() => _error = 'No se pudo cargar tu pago automático.');
      }
    }
  }

  /// Con redirección abre la página de la pasarela; si ya había tarjeta, queda
  /// activo al momento.
  Future<void> _conPasarela(
    String clave,
    Future<String?> Function() accion,
  ) async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _accionando = clave);
    await hacerConAviso(context, () async {
      final String? url;
      try {
        url = await accion();
      } on RequiereWeb {
        messenger.showSnackBar(
          const SnackBar(
            content: Text(
              'Con este negocio la tarjeta se autoriza desde la web: entra a Mi cuenta en el navegador.',
            ),
          ),
        );
        return;
      }
      if (url == null) {
        await _cargar();
        return;
      }
      final abierto = await launchUrl(
        Uri.parse(url),
        mode: LaunchMode.externalApplication,
      );
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            abierto
                ? 'Autoriza tu tarjeta en el navegador; al volver lo verás activo.'
                : 'No se pudo abrir la página de la pasarela.',
          ),
        ),
      );
    });
    if (mounted) {
      setState(() => _accionando = null);
    }
  }

  Future<void> _quitar(MembresiaRenovable m) async {
    final repo = ref.read(cuentaRepositoryProvider);
    final confirmado = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Quitar pago automático'),
        content: const Text(
          'Cada renovación te avisaremos para que la pagues.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancelar'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Quitar'),
          ),
        ],
      ),
    );
    if (confirmado != true || repo == null || !mounted) {
      return;
    }
    setState(() => _accionando = m.id);
    await hacerConAviso(context, () async {
      await repo.quitarPagoAutomatico(m.id);
      await _cargar();
    });
    if (mounted) {
      setState(() => _accionando = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final repo = ref.read(cuentaRepositoryProvider);
    final datos = _datos;

    return Scaffold(
      appBar: AppBar(title: const Text('Pago automático')),
      body: _error != null
          ? Center(child: Text(_error!))
          : datos == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _cargar,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
                children: [
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 8),
                    child: Text(
                      'Tu membresía se renueva sola: se cobra a tu tarjeta en la '
                      'fecha de renovación y te avisamos si algo falla. La tarjeta '
                      'se autoriza en la página segura de la pasarela.',
                      style: TextStyle(color: TemaAgendaUno.textoSuave),
                    ),
                  ),
                  if (!datos.disponible)
                    const Text('Este negocio aún no ofrece pago automático.')
                  else ...[
                    if (datos.tarjeta != null)
                      Card(
                        child: ListTile(
                          leading: const Icon(Icons.credit_card),
                          title: Text(datos.tarjeta!.texto),
                          subtitle: datos.tarjeta!.expira != null
                              ? Text('Vence ${datos.tarjeta!.expira}')
                              : null,
                          trailing: TextButton(
                            onPressed: _accionando != null || repo == null
                                ? null
                                : () => _conPasarela(
                                    'tarjeta',
                                    repo.cambiarTarjeta,
                                  ),
                            child: const Text('Cambiar'),
                          ),
                        ),
                      ),
                    if (datos.membresias.isEmpty)
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 16),
                        child: Text('No tienes membresías que se renueven.'),
                      ),
                    for (final m in datos.membresias)
                      Card(
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      m.producto ?? '—',
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                    Text(
                                      'Se renueva el ${Formato.fechaLarga(m.proximaCobroEn)}'
                                      '${m.montoMinor != null ? ' · ${Formato.dinero(m.montoMinor!, m.moneda)}' : ''}',
                                      style: const TextStyle(
                                        color: TemaAgendaUno.textoSuave,
                                      ),
                                    ),
                                    Text(
                                      m.automatico
                                          ? 'Cobro automático${m.tarjeta != null && datos.tarjeta == null ? ' · ${m.tarjeta!.texto}' : ''}'
                                          : m.pendiente
                                          ? 'Falta autorizarlo en la pasarela'
                                          : 'Te avisamos para pagar',
                                      style: TextStyle(
                                        color: m.automatico
                                            ? TemaAgendaUno.exito
                                            : m.pendiente
                                            ? TemaAgendaUno.aviso
                                            : TemaAgendaUno.textoSuave,
                                      ),
                                    ),
                                    if (m.error != null)
                                      Text(
                                        m.error!,
                                        style: const TextStyle(
                                          color: TemaAgendaUno.error,
                                        ),
                                      ),
                                  ],
                                ),
                              ),
                              if (m.automatico)
                                TextButton(
                                  onPressed: _accionando != null
                                      ? null
                                      : () => _quitar(m),
                                  child: const Text('Quitar'),
                                )
                              else
                                FilledButton(
                                  onPressed: _accionando != null || repo == null
                                      ? null
                                      : () => _conPasarela(
                                          m.id,
                                          () =>
                                              repo.activarPagoAutomatico(m.id),
                                        ),
                                  child: const Text('Activar'),
                                ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ],
              ),
            ),
    );
  }
}
