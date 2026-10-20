import 'package:flutter/material.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../cuenta/data/cuenta_models.dart' show HorarioCita;
import '../data/agenda_models.dart';

/// Lo común de agendar y de reprogramar una cita desde el equipo: los horarios
/// libres del día (o cualquier otra hora, como en la web: el negocio puede agendar
/// fuera del horario de atención), el día y la confirmación con lo que se hará.
///
/// Las horas son siempre las de la sucursal (su hora de pared, sin zona): se
/// muestran tal cual y así se mandan al API (`inicia_en_local`).

/// Los horarios libres como fichas, más «Otra hora». [elegida] es la hora de
/// pared elegida (de una ficha o de «Otra hora»).
class HorariosCita extends StatelessWidget {
  const HorariosCita({
    super.key,
    required this.cargando,
    required this.horarios,
    required this.elegida,
    required this.alElegir,
    required this.alOtraHora,
    required this.alReintentar,
    required this.vacio,
    this.error,
    this.etiquetaZona,
  });

  final bool cargando;
  final List<HorarioCita> horarios;
  final DateTime? elegida;
  final ValueChanged<DateTime> alElegir;
  final VoidCallback? alOtraHora;
  final VoidCallback alReintentar;

  /// Qué decir si ese día no hay huecos libres.
  final String vacio;
  final String? error;

  /// «Hora de Roma Norte» si el teléfono está en otra zona que la sucursal.
  final String? etiquetaZona;

  /// ¿La hora elegida no es uno de los huecos libres? (se eligió con «Otra hora»).
  bool get _otraHora =>
      elegida != null &&
      !horarios.any((h) => paredDeHorario(h.iniciaEnLocal) == elegida);

  @override
  Widget build(BuildContext context) {
    final suave = TextStyle(color: context.colores.textoSuave);
    if (cargando) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 12),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (error != null) {
      return Row(
        children: [
          Expanded(child: Text(error!, style: suave)),
          TextButton(onPressed: alReintentar, child: const Text('Reintentar')),
        ],
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (horarios.isEmpty)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(vacio, key: const Key('sin-horarios'), style: suave),
          ),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final h in horarios)
              if (paredDeHorario(h.iniciaEnLocal) case final pared?)
                ChoiceChip(
                  key: Key('hora-${h.hora}'),
                  label: Text(h.hora),
                  selected: elegida == pared,
                  onSelected: (_) => alElegir(pared),
                ),
            if (_otraHora)
              ChoiceChip(
                key: const Key('hora-otra'),
                label: Text(Formato.hora(elegida!)),
                selected: true,
                onSelected: (_) => alOtraHora?.call(),
              ),
            ActionChip(
              key: const Key('otra-hora'),
              avatar: const Icon(Icons.schedule, size: 18),
              label: const Text('Otra hora'),
              onPressed: alOtraHora,
            ),
          ],
        ),
        if (etiquetaZona != null) ...[
          const SizedBox(height: 8),
          Text(
            etiquetaZona!,
            key: const Key('hora-de-la-sucursal'),
            style: suave,
          ),
        ],
        if (_otraHora) ...[
          const SizedBox(height: 8),
          Text(
            'Esa hora no está entre los horarios libres: se agenda si no choca '
            'con otra cita o con un bloqueo de la agenda.',
            key: const Key('aviso-otra-hora'),
            style: suave,
          ),
        ],
      ],
    );
  }
}

/// El botón del día (abre el calendario).
class BotonDiaCita extends StatelessWidget {
  const BotonDiaCita({super.key, required this.dia, required this.alTocar});

  final DateTime dia;
  final VoidCallback? alTocar;

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
    key: const Key('elegir-dia'),
    icon: const Icon(Icons.calendar_today_outlined, size: 18),
    label: Text(Formato.dia(dia)),
    onPressed: alTocar,
  );
}

/// El calendario para elegir el día de la cita (días de la sucursal): desde hoy
/// (o desde [inicial], si es anterior) hasta un año después.
Future<DateTime?> elegirDiaCita(
  BuildContext context, {
  required DateTime inicial,
  required DateTime hoy,
}) async {
  final desde = inicial.isBefore(hoy) ? inicial : hoy;
  final dia = await showDatePicker(
    context: context,
    initialDate: inicial,
    currentDate: hoy,
    firstDate: desde,
    lastDate: DateTime(hoy.year + 1, hoy.month, hoy.day),
  );
  return dia == null ? null : DateTime(dia.year, dia.month, dia.day);
}

/// Otra hora del [dia] (hora de pared de la sucursal), partiendo de [inicial].
Future<DateTime?> elegirOtraHora(
  BuildContext context, {
  required DateTime dia,
  required DateTime inicial,
}) async {
  final hora = await showTimePicker(
    context: context,
    initialTime: TimeOfDay(hour: inicial.hour, minute: inicial.minute),
    helpText: 'Hora de la cita (la de la sucursal)',
  );
  return hora == null
      ? null
      : DateTime.utc(dia.year, dia.month, dia.day, hora.hour, hora.minute);
}

/// El siguiente cuarto de hora de [ahora] (una hora de pared), en el día [dia].
/// Si [dia] no es el de [ahora], las 10:00 de ese día.
DateTime horaSugerida(DateTime dia, DateTime ahora) {
  final mismoDia =
      dia.year == ahora.year &&
      dia.month == ahora.month &&
      dia.day == ahora.day;
  if (!mismoDia) {
    return DateTime.utc(dia.year, dia.month, dia.day, 10);
  }
  final cuarto = ((ahora.hour * 60 + ahora.minute) / 15).ceil() * 15;
  // Pasadas las 23:45 no hay otro cuarto ese día: la última.
  final minutos = cuarto < 24 * 60 ? cuarto : 23 * 60 + 45;
  return DateTime.utc(
    dia.year,
    dia.month,
    dia.day,
  ).add(Duration(minutes: minutos));
}

/// Pregunta antes de agendar o mover una cita, con lo que se hará a la vista (cada
/// fila: qué y cuál). Devuelve si se aceptó.
Future<bool> confirmarCita(
  BuildContext context, {
  required String titulo,
  String? mensaje,
  required List<(String, String)> filas,
  required String aceptar,
}) async {
  final si = await showDialog<bool>(
    context: context,
    builder: (contexto) => AlertDialog(
      key: const Key('confirmar-cita'),
      title: Text(titulo),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (mensaje != null) ...[Text(mensaje), const SizedBox(height: 12)],
          for (final (que, cual) in filas)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 84,
                    child: Text(
                      que,
                      style: TextStyle(
                        color: contexto.colores.textoSuave,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                  Expanded(child: Text(cual)),
                ],
              ),
            ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(contexto).pop(false),
          child: const Text('Volver'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(contexto).pop(true),
          child: Text(aceptar),
        ),
      ],
    ),
  );
  return si == true;
}

/// El nombre de un profesional del equipo por su id ('—' si no está).
String nombreProfesional(List<Profesional> equipo, String? id) =>
    equipo.where((p) => p.id == id).firstOrNull?.nombre ?? '—';
