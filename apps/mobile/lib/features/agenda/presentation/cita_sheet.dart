import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../auth/application/sesion_controller.dart';
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import 'agenda_screen.dart';

/// Abre la hoja de una cita: quién, qué, a qué hora y cómo va (su atención y su
/// pago, como los calcula el servidor), con las acciones de recepción (llegó, no
/// asistió, cobrar en caja, cancelar).
Future<void> mostrarHojaCita(BuildContext context, SesionAgenda sesion) =>
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _HojaCita(sesionId: sesion.id),
    );

class _HojaCita extends ConsumerStatefulWidget {
  const _HojaCita({required this.sesionId});

  final String sesionId;

  @override
  ConsumerState<_HojaCita> createState() => _HojaCitaState();
}

class _HojaCitaState extends ConsumerState<_HojaCita> {
  var _ocupado = false;
  var _metodo = 'efectivo';

  Future<void> _hacer(
    Future<void> Function(AgendaController c) accion,
    String ok,
  ) async {
    setState(() => _ocupado = true);
    final mensajero = ScaffoldMessenger.of(context);
    try {
      await accion(ref.read(agendaProvider.notifier));
      mensajero.showSnackBar(SnackBar(content: Text(ok)));
    } on DioException catch (e) {
      final data = e.response?.data;
      final msg = data is Map<String, dynamic>
          ? (data['message'] ?? 'No se pudo completar.')
          : 'No se pudo completar.';
      mensajero.showSnackBar(SnackBar(content: Text('$msg')));
    } finally {
      if (mounted) {
        setState(() => _ocupado = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final agenda = ref.watch(agendaProvider).value;
    // Solo se ofrece lo que el servidor permitiría (un instructor no cobra ni cancela).
    final sesion = ref.watch(sesionProvider);
    final puedeCobrar = sesion?.puede('ordenes.gestionar') ?? false;
    final puedeMarcar = sesion?.puede('asistencia.marcar') ?? false;
    final puedeCancelar = sesion?.puede('reservas.gestionar') ?? false;
    final s = agenda?.sesiones
        .where((x) => x.id == widget.sesionId)
        .firstOrNull;
    if (s == null || s.cita == null) {
      return const SizedBox(
        height: 120,
        child: Center(child: CircularProgressIndicator()),
      );
    }
    final estado = s.estadoCita;
    final pago = s.porCobrar ? s.cita!.estadoPago : null;
    final tono = TonoServicio.de(s.ofertaId);
    // Cancelada, completada o sin asistencia: ya no hay acciones.
    final activa = !(estado?.cerrada ?? false);
    // Con centavos y en la moneda del negocio (una sola, ADR 0099).
    final precio = s.precioMinor != null
        ? Formato.dinero(s.precioMinor!, sesion?.moneda ?? 'MXN')
        : null;
    String hhmm(DateTime d) =>
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                if (estado != null) ...[
                  Chip(
                    label: Text(
                      estado.etiqueta,
                      style: TextStyle(
                        color: estiloEstado(estado).$2,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    backgroundColor: estiloEstado(estado).$1,
                    side: BorderSide.none,
                  ),
                  const SizedBox(width: 8),
                ],
                if (pago != null)
                  Chip(
                    label: Text(
                      pago.etiqueta,
                      style: TextStyle(
                        color: estiloPago.$2,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    backgroundColor: estiloPago.$1,
                    side: BorderSide.none,
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                CircleAvatar(
                  radius: 26,
                  backgroundColor: tono.fondo,
                  child: Text(
                    Profesional(
                      id: '',
                      nombre: s.cita!.cliente ?? '?',
                    ).iniciales,
                    style: TextStyle(
                      color: tono.tinta,
                      fontWeight: FontWeight.w800,
                      fontSize: 17,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        s.cita!.cliente ?? 'Sin cliente',
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      Text(
                        '${hhmm(s.iniciaEn)}–${hhmm(s.terminaEn)} · ${s.oferta ?? '—'}',
                        style: const TextStyle(
                          color: Color(0xFF596275),
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              'Con ${s.instructor ?? '—'}${precio != null ? ' · $precio' : ''}',
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            if (activa) ...[
              const SizedBox(height: 16),
              if (s.porCobrar && puedeCobrar)
                Row(
                  children: [
                    DropdownButton<String>(
                      value: _metodo,
                      onChanged: _ocupado
                          ? null
                          : (v) => setState(() => _metodo = v ?? 'efectivo'),
                      items: const [
                        DropdownMenuItem(
                          value: 'efectivo',
                          child: Text('Efectivo'),
                        ),
                        DropdownMenuItem(
                          value: 'transferencia',
                          child: Text('Transferencia'),
                        ),
                        DropdownMenuItem(
                          value: 'manual',
                          child: Text('Tarjeta (terminal)'),
                        ),
                      ],
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton(
                        onPressed: _ocupado
                            ? null
                            : () => _hacer(
                                (c) => c.cobrar(s, _metodo),
                                'Cita cobrada.',
                              ),
                        child: Text('Cobrar ${precio ?? ''}'),
                      ),
                    ),
                  ],
                ),
              const SizedBox(height: 8),
              if (puedeMarcar)
                Row(
                  children: [
                    if (s.cita!.asistencia != 'presente')
                      Expanded(
                        child: FilledButton.tonal(
                          onPressed: _ocupado
                              ? null
                              : () => _hacer(
                                  (c) => c.marcarLlegada(s),
                                  'Llegada registrada.',
                                ),
                          child: const Text('Llegó'),
                        ),
                      ),
                    if (s.cita!.asistencia != 'presente')
                      const SizedBox(width: 8),
                    // Llegó, pero tarde: cuenta como que llegó.
                    if (s.cita!.asistencia != 'presente' || !s.cita!.retardo)
                      Expanded(
                        child: OutlinedButton(
                          onPressed: _ocupado
                              ? null
                              : () => _hacer(
                                  (c) => c.marcarLlegada(s, retardo: true),
                                  'Se registró que llegó tarde.',
                                ),
                          child: const Text('Llegó tarde'),
                        ),
                      ),
                    if (s.cita!.asistencia != 'presente' || !s.cita!.retardo)
                      const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton(
                        onPressed: _ocupado
                            ? null
                            : () => _hacer(
                                (c) => c.marcarNoAsistio(s),
                                'Se marcó como no asistió.',
                              ),
                        child: const Text('No asistió'),
                      ),
                    ),
                  ],
                ),
              if (puedeCancelar)
                TextButton(
                  onPressed: _ocupado
                      ? null
                      : () => _hacer(
                          (c) => c.cancelar(s),
                          'Cita cancelada; el horario quedó libre.',
                        ),
                  style: TextButton.styleFrom(
                    foregroundColor: const Color(0xFFB42318),
                  ),
                  child: const Text('Cancelar cita'),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
