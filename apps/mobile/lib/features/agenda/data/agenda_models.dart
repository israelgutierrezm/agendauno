import 'dart:ui';

/// Estado operativo de una cita (mismo criterio que la agenda web).
enum EstadoCita {
  pendientePago('Pago pendiente'),
  confirmada('Confirmada'),
  llego('Llegó'),
  enServicio('En servicio'),
  completada('Completada'),
  noAsistio('No asistió'),
  cancelada('Cancelada');

  const EstadoCita(this.etiqueta);

  final String etiqueta;
}

/// Titular de una cita: a quién se atiende y cómo va su reserva.
class CitaTitular {
  const CitaTitular({
    required this.reservaId,
    required this.cliente,
    required this.estado,
    this.asistencia,
    this.ordenId,
    this.porCobrar = false,
  });

  final String reservaId;
  final String? cliente;
  final String estado; // confirmada | pendiente_pago …
  final String? asistencia; // presente | ausente | null
  final String? ordenId;
  final bool porCobrar;

  factory CitaTitular.desdeJson(Map<String, dynamic> json) => CitaTitular(
    reservaId: json['reserva_id'] as String,
    cliente: json['cliente'] as String?,
    estado: (json['estado'] ?? 'confirmada') as String,
    asistencia: json['asistencia'] as String?,
    ordenId: json['orden_id'] as String?,
    porCobrar: (json['por_cobrar'] ?? false) as bool,
  );
}

/// Sesión de la agenda del staff (clase abierta o cita privada), en hora local.
class SesionAgenda {
  const SesionAgenda({
    required this.id,
    required this.tipo,
    required this.oferta,
    required this.ofertaId,
    required this.instructor,
    required this.instructorId,
    required this.sala,
    required this.iniciaEn,
    required this.terminaEn,
    required this.capacidad,
    required this.ocupados,
    required this.enEspera,
    required this.estado,
    this.precioMinor,
    this.cita,
  });

  final String id;
  final String tipo;
  final String? oferta;
  final String? ofertaId;
  final String? instructor;
  final String? instructorId;
  final String? sala;
  final DateTime iniciaEn;
  final DateTime terminaEn;
  final int? capacidad;
  final int ocupados;
  final int enEspera;
  final String estado;
  final int? precioMinor;
  final CitaTitular? cita;

  bool get esCita => tipo == 'cita';
  bool get programada => estado == 'programada';
  int get duracionMin => terminaEn.difference(iniciaEn).inMinutes;

  /// Ocupación 0–1 de una clase (null si no tiene cupo definido).
  double? get ocupacion => capacidad != null && capacidad! > 0 ? (ocupados / capacidad!).clamp(0, 1).toDouble() : null;

  /// Estado de la cita a la hora `ahora`: pagada o no, y si el cliente llegó.
  EstadoCita estadoCita(DateTime ahora) {
    final c = cita;
    if (!programada || c == null) {
      return EstadoCita.cancelada;
    }
    if (c.asistencia == 'ausente') {
      return EstadoCita.noAsistio;
    }
    if (c.asistencia == 'presente') {
      if (ahora.isBefore(iniciaEn)) {
        return EstadoCita.llego;
      }
      return ahora.isBefore(terminaEn) ? EstadoCita.enServicio : EstadoCita.completada;
    }
    return c.estado == 'pendiente_pago' ? EstadoCita.pendientePago : EstadoCita.confirmada;
  }

  /// ¿Falta cobrarla? (pendiente de pago en línea o agendada por el negocio sin cobrar).
  bool get porCobrar {
    final c = cita;
    return c != null && c.ordenId != null && (c.estado == 'pendiente_pago' || c.porCobrar);
  }

  factory SesionAgenda.desdeJson(Map<String, dynamic> json) {
    final cita = json['cita'];
    return SesionAgenda(
      id: json['id'] as String,
      tipo: (json['tipo'] ?? 'clase') as String,
      oferta: json['oferta'] as String?,
      ofertaId: json['oferta_id'] as String?,
      instructor: json['instructor'] as String?,
      instructorId: json['instructor_id'] as String?,
      sala: json['sala'] as String?,
      iniciaEn: DateTime.parse(json['inicia_en'] as String).toLocal(),
      terminaEn: DateTime.parse(json['termina_en'] as String).toLocal(),
      capacidad: json['capacidad'] as int?,
      ocupados: (json['ocupados'] ?? 0) as int,
      enEspera: (json['en_espera'] ?? 0) as int,
      estado: (json['estado'] ?? 'programada') as String,
      precioMinor: json['oferta_precio_clase'] as int?,
      cita: cita is Map<String, dynamic> ? CitaTitular.desdeJson(cita) : null,
    );
  }
}

/// Profesional/instructor (columna de la agenda de citas).
class Profesional {
  const Profesional({required this.id, required this.nombre});

  final String id;
  final String nombre;

  String get iniciales {
    final partes = nombre.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).take(2);
    return partes.map((p) => p[0].toUpperCase()).join();
  }

  factory Profesional.desdeJson(Map<String, dynamic> json) =>
      Profesional(id: json['id'] as String, nombre: (json['nombre'] ?? '') as String);
}

/// Asistente en la lista de una clase (roster) para el pase de lista.
class Asistente {
  const Asistente({
    required this.reservaId,
    required this.nombre,
    required this.estado,
    this.asistencia,
    this.primeraVez = false,
    this.adeudo = false,
  });

  final String reservaId;
  final String nombre;
  final String estado; // confirmada | ofrecida | en_espera | pendiente_pago
  final String? asistencia; // presente | ausente | null
  final bool primeraVez;
  final bool adeudo;

  bool get enSala => estado != 'en_espera';
  bool get llego => asistencia == 'presente';

  factory Asistente.desdeJson(Map<String, dynamic> json) => Asistente(
    reservaId: json['id'] as String,
    nombre: (json['persona'] ?? '—') as String,
    estado: (json['estado'] ?? 'confirmada') as String,
    asistencia: json['asistencia'] as String?,
    primeraVez: (json['primera_vez'] ?? false) as bool,
    adeudo: (json['adeudo'] ?? false) as bool,
  );
}

/// Colores de servicio (fondo claro + tinta del mismo matiz), como en la web.
class TonoServicio {
  const TonoServicio(this.fondo, this.tinta);

  final Color fondo;
  final Color tinta;

  static const paleta = [
    TonoServicio(Color(0xFFDCE9FF), Color(0xFF0B3A8C)),
    TonoServicio(Color(0xFFD6F2EA), Color(0xFF0A5A47)),
    TonoServicio(Color(0xFFFCE0EB), Color(0xFF8A1747)),
    TonoServicio(Color(0xFFEBE3FF), Color(0xFF4A2A8F)),
    TonoServicio(Color(0xFFFDE7D6), Color(0xFF7A3708)),
    TonoServicio(Color(0xFFFFF1CC), Color(0xFF6B4700)),
    TonoServicio(Color(0xFFDFF3FB), Color(0xFF0B5470)),
    TonoServicio(Color(0xFFE6F4D7), Color(0xFF3B5A12)),
  ];

  /// Tono estable por servicio (hash del id).
  static TonoServicio de(String? ofertaId) {
    var h = 0;
    for (final c in (ofertaId ?? '').codeUnits) {
      h = (h * 31 + c) & 0x7fffffff;
    }
    return paleta[h % paleta.length];
  }
}

/// Colores sólidos por profesional (avatar y chip).
const coloresProfesional = [
  Color(0xFF2563EB),
  Color(0xFF0B8468),
  Color(0xFFC2410C),
  Color(0xFF7C3AED),
  Color(0xFFDB2777),
  Color(0xFF0E7490),
  Color(0xFF4D7C0F),
  Color(0xFFB45309),
];
