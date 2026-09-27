import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../features/auth/application/sesion_controller.dart';
import 'calendario.dart';

/// "Agregar a mi calendario" de una reserva o una clase: Google Calendar (enlace
/// directo) o el .ics que sirve el servidor (Apple Calendar, Outlook y los demás).
/// `evento` es `reserva-{id}` o `sesion-{id}`.
Future<void> mostrarAgregarCalendario(
  BuildContext context,
  WidgetRef ref, {
  required String evento,
  required String titulo,
  required DateTime inicio,
  DateTime? fin,
  String? lugar,
}) => showModalBottomSheet<void>(
  context: context,
  showDragHandle: true,
  builder: (ctx) => SafeArea(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: Text(
            'Agregar a mi calendario',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
          ),
        ),
        ListTile(
          leading: const Icon(Icons.event_outlined),
          title: const Text('Google Calendar'),
          onTap: () {
            Navigator.pop(ctx);
            _abrir(
              context,
              Calendario.enlaceGoogle(
                titulo: titulo,
                inicio: inicio,
                fin: fin,
                lugar: lugar,
              ),
            );
          },
        ),
        ListTile(
          leading: const Icon(Icons.calendar_month_outlined),
          title: const Text('Apple, Outlook y otros'),
          subtitle: const Text('Se descarga el evento (.ics)'),
          onTap: () async {
            Navigator.pop(ctx);
            final messenger = ScaffoldMessenger.of(context);
            try {
              final url = await ref
                  .read(sesionProvider.notifier)
                  .enlaceEvento(evento);
              if (url != null && context.mounted) {
                await _abrir(context, url);
              }
            } on DioException {
              messenger.showSnackBar(
                const SnackBar(content: Text('No se pudo preparar el evento.')),
              );
            }
          },
        ),
        const SizedBox(height: 8),
      ],
    ),
  ),
);

Future<void> _abrir(BuildContext context, String url) async {
  final messenger = ScaffoldMessenger.of(context);
  final abierto = await launchUrl(
    Uri.parse(url),
    mode: LaunchMode.externalApplication,
  );
  if (!abierto) {
    messenger.showSnackBar(
      const SnackBar(content: Text('No se pudo abrir el calendario.')),
    );
  }
}

/// Botón "Agregar a mi calendario" (abre las opciones).
class BotonAgregarCalendario extends ConsumerWidget {
  const BotonAgregarCalendario({
    super.key,
    required this.evento,
    required this.titulo,
    required this.inicio,
    this.fin,
    this.lugar,
    this.primario = false,
  });

  final String evento;
  final String titulo;
  final DateTime inicio;
  final DateTime? fin;
  final String? lugar;

  /// Relleno (acción principal de la tarjeta) en vez de contorno.
  final bool primario;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    void abrir() => mostrarAgregarCalendario(
      context,
      ref,
      evento: evento,
      titulo: titulo,
      inicio: inicio,
      fin: fin,
      lugar: lugar,
    );
    const icono = Icon(Icons.event_available_outlined, size: 18);
    const texto = Text('Agregar a mi calendario');
    return primario
        ? FilledButton.icon(icon: icono, label: texto, onPressed: abrir)
        : OutlinedButton.icon(icon: icono, label: texto, onPressed: abrir);
  }
}
