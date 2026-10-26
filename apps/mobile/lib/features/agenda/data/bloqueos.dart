import '../../../core/formato.dart';

/// Un bloqueo de la agenda (`/bloqueos`, fase 2.2): comida, vacaciones o ausencia de
/// un profesional, o el cierre de una sede. Quita ese intervalo de lo que se puede
/// agendar, pero no cancela lo que ya estaba agendado (la vista previa lo dice).
class BloqueoAgenda {
  const BloqueoAgenda({
    required this.id,
    required this.ambito,
    required this.desde,
    required this.hasta,
    this.instructorId,
    this.sucursalId,
    this.nombre,
    this.todoElDia = false,
    this.motivo,
    this.creadoPor,
    this.origen,
  });

  final String id;

  /// `profesional`, `sede` o `sala`.
  final String ambito;
  final String? instructorId;
  final String? sucursalId;

  /// A quién o qué bloquea (el profesional, la sede o la sala).
  final String? nombre;

  /// El intervalo (instantes); con [todoElDia], de las 00:00 del primer día a las
  /// 00:00 del siguiente al último, en la zona de la sede o del negocio.
  final DateTime desde;
  final DateTime hasta;
  final bool todoElDia;
  final String? motivo;
  final String? creadoPor;

  /// Viene del Google Calendar u Outlook de esa persona (ADR 0199): se quita allá.
  final String? origen;

  bool get deCalendario => origen != null;

  /// «Google Calendar» u «Outlook».
  String? get origenTexto => switch (origen) {
    null => null,
    'google' => 'Google Calendar',
    'outlook' || 'microsoft' => 'Outlook',
    final o => o,
  };

  bool get dePersona => ambito == 'profesional';

  /// ¿Se cruza con el día [dia] (día de pared) en esa zona? [pared] pasa un instante
  /// a la hora de pared del negocio.
  bool tocaElDia(DateTime dia, DateTime Function(DateTime) pared) {
    final inicio = DateTime(dia.year, dia.month, dia.day);
    final fin = inicio.add(const Duration(days: 1));
    return pared(desde).isBefore(fin) && pared(hasta).isAfter(inicio);
  }

  factory BloqueoAgenda.desdeJson(Map<String, dynamic> j) => BloqueoAgenda(
    id: (j['id'] ?? '') as String,
    ambito: (j['ambito'] ?? 'profesional') as String,
    instructorId: j['instructor_id'] as String?,
    sucursalId: j['sucursal_id'] as String?,
    nombre: _texto(j['nombre']),
    desde: DateTime.parse(j['desde'] as String).toLocal(),
    hasta: DateTime.parse(j['hasta'] as String).toLocal(),
    todoElDia: j['todo_el_dia'] == true,
    motivo: _texto(j['motivo']),
    creadoPor: _texto(j['creado_por']),
    origen: _texto(j['origen']),
  );

  static List<BloqueoAgenda> lista(Object? valor) => valor is List
      ? valor
            .whereType<Map<String, dynamic>>()
            .where((j) => j['desde'] is String && j['hasta'] is String)
            .map(BloqueoAgenda.desdeJson)
            .toList(growable: false)
      : const [];
}

/// Lo que ya estaba agendado en el intervalo de un bloqueo: sigue en pie y hay que
/// atenderlo (moverlo o avisar).
class SesionAfectada {
  const SesionAfectada({
    required this.sesionId,
    this.oferta,
    this.iniciaEn,
    this.reservas = 0,
  });

  final String sesionId;
  final String? oferta;
  final DateTime? iniciaEn;

  /// Cuántas personas tienen reservado.
  final int reservas;

  factory SesionAfectada.desdeJson(Map<String, dynamic> j) => SesionAfectada(
    sesionId: (j['sesion'] ?? '') as String,
    oferta: _texto(j['oferta']),
    iniciaEn: j['inicia_en'] is String
        ? DateTime.tryParse(j['inicia_en'] as String)?.toLocal()
        : null,
    reservas: (j['reservas'] as num? ?? 0).toInt(),
  );

  static List<SesionAfectada> lista(Object? valor) => valor is List
      ? valor
            .whereType<Map<String, dynamic>>()
            .map(SesionAfectada.desdeJson)
            .toList(growable: false)
      : const [];
}

/// Lo que se pide para bloquear a un profesional (`POST /bloqueos`): un rato de un
/// día (horas de pared del negocio, `desde_local`/`hasta_local`) o días completos
/// (`fecha_desde`/`fecha_hasta`), con su motivo.
class NuevoBloqueo {
  const NuevoBloqueo({
    required this.instructorId,
    required this.dia,
    required this.motivo,
    this.desdeMinuto,
    this.hastaMinuto,
    this.hastaDia,
  });

  final String instructorId;

  /// El día (de pared) y, por horas, de qué minuto a qué minuto del día.
  final DateTime dia;
  final int? desdeMinuto;
  final int? hastaMinuto;

  /// Días completos: hasta qué día (incluido; sin él, solo [dia]).
  final DateTime? hastaDia;
  final String motivo;

  bool get todoElDia => desdeMinuto == null || hastaMinuto == null;

  static String _hora(DateTime dia, int minuto) =>
      '${Formato.iso(dia)} ${(minuto ~/ 60).toString().padLeft(2, '0')}:'
      '${(minuto % 60).toString().padLeft(2, '0')}:00';

  Map<String, dynamic> aJson() => {
    'instructor_id': instructorId,
    'motivo': motivo.trim(),
    if (todoElDia) ...{
      'fecha_desde': Formato.iso(dia),
      'fecha_hasta': Formato.iso(hastaDia ?? dia),
    } else ...{
      'desde_local': _hora(dia, desdeMinuto!),
      'hasta_local': _hora(dia, hastaMinuto!),
    },
  };
}

String? _texto(Object? v) =>
    v is String && v.trim().isNotEmpty ? v.trim() : null;
