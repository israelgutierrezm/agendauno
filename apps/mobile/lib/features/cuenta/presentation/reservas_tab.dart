import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/calendario/calendario.dart';
import '../../../core/calendario/calendario_vistas.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/eventos_cuenta.dart';
import 'agendar_cita_sheet.dart';
import 'cuenta_widgets.dart';

/// Reservas del portal: las suyas y (en negocios de clases) las clases a las que
/// puede entrar, en lista o calendario (día, semana, mes). Tocar una abre su
/// detalle; en negocios de citas, "Agendar una cita".
class ReservasTab extends ConsumerWidget {
  const ReservasTab({super.key, required this.cuenta});

  final MiCuenta cuenta;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    final terminos = sesion?.terminologia ?? const Terminologia();
    final clases = terminos.sesiones.toLowerCase();
    final esCitas = sesion?.esCitas ?? false;
    final eventos = eventosDeCuenta(cuenta);
    final mias = eventos.where((e) => e.destacado).toList();
    final libres = eventos.where((e) => !e.destacado).toList();

    void abrir(EventoCal e) {
      final reserva = cuenta.reservas.where((r) => r.id == e.id).firstOrNull;
      if (reserva != null) {
        mostrarDetalleReserva(
          context,
          reserva,
          pagoEnLinea: cuenta.pagoEnLinea,
        );
        return;
      }
      final clase = cuenta.clases.where((c) => c.id == e.id).firstOrNull;
      if (clase != null) {
        mostrarDetalleClase(context, clase);
      }
    }

    return RefreshIndicator(
      onRefresh: () => ref.refresh(cuentaProvider.future),
      child: CalendarioVistas(
        eventos: eventos,
        onAbrir: abrir,
        encabezado: [
          if (esCitas)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: FilledButton.icon(
                icon: const Icon(Icons.add),
                label: const Text('Agendar una cita'),
                onPressed: () => showModalBottomSheet<void>(
                  context: context,
                  isScrollControlled: true,
                  showDragHandle: true,
                  builder: (_) => const AgendarCitaSheet(),
                ),
              ),
            ),
        ],
        lista: [
          const TituloSeccion('Mis reservas'),
          if (mias.isEmpty)
            const TextoVacio('No tienes reservas próximas.')
          else
            Card(
              child: Column(
                children: [
                  for (final e in mias)
                    FilaEvento(e, onTap: abrir, conFecha: true),
                ],
              ),
            ),
          if (!esCitas) ...[
            TituloSeccion('${terminos.sesiones} disponibles'),
            if (libres.isEmpty)
              TextoVacio('No hay $clases programadas.')
            else
              Card(
                child: Column(
                  children: [
                    for (final e in libres)
                      FilaEvento(e, onTap: abrir, conFecha: true),
                  ],
                ),
              ),
          ],
          if (cuenta.resenasPendientes.isNotEmpty) ...[
            TituloSeccion('Califica tus $clases'),
            for (final r in cuenta.resenasPendientes)
              CalificarClaseCard(r, key: ValueKey(r.reservaId)),
          ],
          const Padding(
            padding: EdgeInsets.only(top: 12),
            child: Text(
              'Para ver todas tus reservas en el calendario del teléfono, conéctalo en Mi perfil.',
              style: TextStyle(color: TemaAgendaUno.textoSuave, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }
}
