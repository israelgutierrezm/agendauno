/// Calendario personal (alumno o instructor): el evento que se pinta, las vistas y
/// las cuentas de fechas. Todo en hora local del dispositivo; sin dependencias.
enum VistaCal { lista, dia, semana, mes }

extension EtiquetaVista on VistaCal {
  String get etiqueta => switch (this) {
    VistaCal.lista => 'Lista',
    VistaCal.dia => 'Día',
    VistaCal.semana => 'Semana',
    VistaCal.mes => 'Mes',
  };
}

/// Cómo se pinta el texto de estado: lo propio en primario, lo que pide atención
/// en ámbar y lo demás en gris.
enum TonoEvento { primario, aviso, suave }

class EventoCal {
  const EventoCal({
    required this.id,
    required this.titulo,
    required this.inicio,
    this.fin,
    this.detalle,
    this.estado,
    this.tono = TonoEvento.suave,
    this.destacado = false,
  });

  final String id;
  final String titulo;
  final DateTime inicio;
  final DateTime? fin;

  /// Segunda línea: sede, con quién, cupo…
  final String? detalle;

  /// Texto corto a la derecha (estado, lugares).
  final String? estado;
  final TonoEvento tono;

  /// Lo propio (sus reservas, sus clases): resaltado en semana y mes.
  final bool destacado;
}

abstract final class Calendario {
  static const diasCortos = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
  static const diasSemana = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
  static const meses = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
  ];

  static DateTime dia(DateTime d) => DateTime(d.year, d.month, d.day);

  /// Suma días de calendario (sin depender de horarios de verano).
  static DateTime mas(DateTime d, int dias) =>
      DateTime(d.year, d.month, d.day + dias);

  static bool mismoDia(DateTime a, DateTime b) =>
      a.year == b.year && a.month == b.month && a.day == b.day;

  static DateTime lunesDe(DateTime d) => mas(dia(d), -(d.weekday - 1));

  /// Semanas (lunes a domingo) que cubren el mes de `fecha`.
  static List<List<DateTime>> semanasDelMes(DateTime fecha) {
    final primero = DateTime(fecha.year, fecha.month);
    final ultimo = DateTime(fecha.year, fecha.month + 1, 0);
    final semanas = <List<DateTime>>[];
    for (var l = lunesDe(primero); !l.isAfter(ultimo); l = mas(l, 7)) {
      semanas.add([for (var i = 0; i < 7; i++) mas(l, i)]);
    }
    return semanas;
  }

  /// Fechas visibles (ambas incluidas): la lista ve los próximos 30 días; el mes,
  /// sus semanas completas.
  static ({DateTime desde, DateTime hasta}) rango(
    VistaCal vista,
    DateTime fecha,
    DateTime hoy,
  ) {
    switch (vista) {
      case VistaCal.dia:
        return (desde: dia(fecha), hasta: dia(fecha));
      case VistaCal.semana:
        final l = lunesDe(fecha);
        return (desde: l, hasta: mas(l, 6));
      case VistaCal.mes:
        final semanas = semanasDelMes(fecha);
        return (desde: semanas.first.first, hasta: semanas.last.last);
      case VistaCal.lista:
        return (desde: dia(hoy), hasta: mas(hoy, 30));
    }
  }

  /// Mueve un periodo (día, semana o mes) hacia atrás o adelante.
  static DateTime mover(VistaCal vista, DateTime fecha, int delta) =>
      switch (vista) {
        VistaCal.dia => mas(fecha, delta),
        VistaCal.semana => mas(fecha, 7 * delta),
        _ => DateTime(fecha.year, fecha.month + delta),
      };

  /// "Martes 23 de septiembre", "21 – 27 sep" o "Septiembre de 2026".
  static String etiqueta(VistaCal vista, DateTime fecha) {
    switch (vista) {
      case VistaCal.dia:
        const largos = [
          'Lunes',
          'Martes',
          'Miércoles',
          'Jueves',
          'Viernes',
          'Sábado',
          'Domingo',
        ];
        return '${largos[fecha.weekday - 1]} ${fecha.day} de ${meses[fecha.month - 1]}';
      case VistaCal.semana:
        final a = lunesDe(fecha);
        final b = mas(a, 6);
        String corto(DateTime d) =>
            '${d.day} ${meses[d.month - 1].substring(0, 3)}';
        return '${corto(a)} – ${corto(b)}';
      default:
        final m = meses[fecha.month - 1];
        return '${m[0].toUpperCase()}${m.substring(1)} de ${fecha.year}';
    }
  }

  static List<EventoCal> delDia(List<EventoCal> eventos, DateTime d) =>
      eventos.where((e) => mismoDia(e.inicio, d)).toList()
        ..sort((a, b) => a.inicio.compareTo(b.inicio));

  /// Primer día con algo después de `despuesDe` (para "Ver lo siguiente").
  static DateTime? siguienteConAlgo(
    List<EventoCal> eventos,
    DateTime despuesDe,
  ) {
    final limite = mas(despuesDe, 1);
    final futuros =
        eventos
            .map((e) => dia(e.inicio))
            .where((d) => !d.isBefore(limite))
            .toList()
          ..sort();
    return futuros.isEmpty ? null : futuros.first;
  }

  /// 20301008T150000Z (UTC), como lo piden Google Calendar y el .ics.
  static String utc(DateTime d) {
    final u = d.toUtc();
    String dos(int n) => n.toString().padLeft(2, '0');
    return '${u.year}${dos(u.month)}${dos(u.day)}T${dos(u.hour)}${dos(u.minute)}${dos(u.second)}Z';
  }

  /// Enlace para agregar el evento en Google Calendar (sin fin, dura una hora).
  static String enlaceGoogle({
    required String titulo,
    required DateTime inicio,
    DateTime? fin,
    String? lugar,
    String? detalle,
  }) {
    final hasta = fin ?? inicio.add(const Duration(hours: 1));
    return Uri.https('calendar.google.com', '/calendar/render', {
      'action': 'TEMPLATE',
      'text': titulo,
      'dates': '${utc(inicio)}/${utc(hasta)}',
      if (lugar != null && lugar.isNotEmpty) 'location': lugar,
      if (detalle != null && detalle.isNotEmpty) 'details': detalle,
    }).toString();
  }
}
