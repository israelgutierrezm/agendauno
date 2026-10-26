import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../../auth/data/sesion.dart';
import '../../auth/presentation/abrir_en_la_web.dart';
import '../data/calendarios_externos.dart';

/// ¿Atiende (clases o citas)? Solo así sincroniza su Google Calendar u Outlook
/// (ADR 0199).
bool atiende(Sesion sesion) =>
    sesion.roles.contains('instructor') ||
    sesion.rolesDisponibles.any((r) => r.faceta == 'instructor');

/// Sincronizar con Google u Outlook: cómo está y, para conectarlo o quitarlo, abrir
/// la web con la misma sesión (autorizar en Google o Microsoft se hace allá).
Future<void> mostrarCalendariosExternos(BuildContext context) =>
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (_) => const CalendariosExternosSheet(),
    );

class CalendariosExternosSheet extends ConsumerWidget {
  const CalendariosExternosSheet({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final estado = ref.watch(calendariosExternosProvider);
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Sincronizar con Google u Outlook',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 4),
            Text(
              'Tus clases y citas aparecen en un calendario aparte dentro de tu '
              'cuenta, y lo que tengas ocupado en tu calendario principal bloquea '
              'tu agenda aquí. Nadie más ve tus eventos: solo se lee si estás '
              'ocupado.',
              style: TextStyle(color: context.colores.textoSuave),
            ),
            const SizedBox(height: 12),
            ...switch (estado) {
              AsyncData(:final value?) => [
                for (final c in value)
                  ListTile(
                    key: ValueKey('calendario-${c.proveedor}'),
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.event_available_outlined),
                    title: Text(c.nombre),
                    subtitle: Text(
                      c.conectado
                          ? 'Conectado${c.cuenta == null ? '' : ' · ${c.cuenta}'}'
                          : 'Sin conectar',
                    ),
                  ),
                const SizedBox(height: 8),
                FilledButton.tonal(
                  key: const Key('calendarios-en-la-web'),
                  onPressed: () async {
                    final nav = Navigator.of(context);
                    if (await abrirEnLaWeb(
                      context,
                      ref,
                      DestinosWeb.calendarios,
                    )) {
                      nav.pop();
                    }
                  },
                  child: const Text('Conectar o quitar en la web'),
                ),
              ],
              AsyncData() => [
                const Text(
                  'Tu negocio aún no tiene esta sincronización disponible.',
                  key: Key('calendarios-no-disponibles'),
                ),
              ],
              _ => [const Center(child: CircularProgressIndicator())],
            },
          ],
        ),
      ),
    );
  }
}
