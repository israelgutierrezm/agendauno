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
import 'historial_screen.dart';

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
    // Las clases del periodo que se ve (al moverse se conserva lo anterior mientras
    // llega lo nuevo); si falla, se dice, no se muestra un periodo vacío.
    final periodo = esCitas ? null : ref.watch(clasesPeriodoProvider);
    final delPeriodo = periodo?.value?.clases ?? const <ClaseMiembro>[];
    final errorPeriodo =
        periodo != null && periodo.hasError && !periodo.isLoading;
    final sedes = periodo?.value?.sucursales ?? const <SedeAgenda>[];
    final filtro = ref.watch(filtroAgendaProvider);
    final eventos = eventosDeCuenta(cuenta, clases: delPeriodo);
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
      final clase = delPeriodo.where((c) => c.id == e.id).firstOrNull;
      if (clase != null) {
        mostrarDetalleClase(context, clase);
      }
    }

    return RefreshIndicator(
      onRefresh: () => ref.refresh(cuentaProvider.future),
      child: CalendarioVistas(
        eventos: eventos,
        onAbrir: abrir,
        onRango: esCitas
            ? null
            : (desde, hasta) => ref
                  .read(filtroAgendaProvider.notifier)
                  .fijarPeriodo(desde, hasta),
        encabezado: [
          if (periodo != null && periodo.isLoading)
            const LinearProgressIndicator(minHeight: 2),
          if (errorPeriodo)
            Card(
              child: ListTile(
                title: Text(
                  'No se pudieron cargar las $clases de estas fechas.',
                  style: const TextStyle(color: TemaAgendaUno.error),
                ),
                trailing: TextButton(
                  onPressed: () => ref.invalidate(clasesPeriodoProvider),
                  child: const Text('Reintentar'),
                ),
              ),
            ),
          if (sedes.length > 1)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: DropdownButtonFormField<String>(
                initialValue: filtro.sucursal,
                decoration: const InputDecoration(labelText: 'Sucursal'),
                items: [
                  const DropdownMenuItem(
                    value: '',
                    child: Text('Todas las sucursales'),
                  ),
                  for (final s in sedes)
                    DropdownMenuItem(value: s.id, child: Text(s.nombre)),
                ],
                onChanged: (v) => ref
                    .read(filtroAgendaProvider.notifier)
                    .elegirSucursal(v ?? ''),
              ),
            ),
          if (periodo?.value?.truncado ?? false)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(
                'Hay más $clases en estas fechas de las que caben aquí: elige una sucursal o un periodo más corto.',
                style: const TextStyle(color: TemaAgendaUno.aviso),
              ),
            ),
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
            if (errorPeriodo)
              const SizedBox.shrink()
            else if (libres.isEmpty)
              TextoVacio('No hay $clases programadas en estas fechas.')
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
          // Lo que ya pasó: asistencias, cancelaciones y cambios, con su reseña.
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Card(
              child: ListTile(
                title: const Text('Historial'),
                subtitle: Text('Tus $clases y citas anteriores'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => const HistorialScreen(),
                  ),
                ),
              ),
            ),
          ),
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
