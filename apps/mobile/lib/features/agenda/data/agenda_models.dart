import 'dart:ui';

import '../../../core/agenda/contrato_agenda.dart';

export '../../../core/agenda/contrato_agenda.dart';

/// Titular de una cita: a quién se atiende, cómo va su reserva y en qué va su
/// atención y su pago (calculados por el servidor).
class CitaTitular {
  const CitaTitular({
    required this.reservaId,
    required this.cliente,
    required this.estado,
    this.asistencia,
    this.retardo = false,
    this.ordenId,
    this.estadoAtencion,
    this.estadoPago,
  });

  final String reservaId;
  final String? cliente;
  final String estado; // confirmada | pendiente_pago …
  final String? asistencia; // presente | ausente | null

  /// Llegó tarde (cuenta como que llegó).
  final bool retardo;
  final String? ordenId;

  /// En qué va: confirmada, llegó, en servicio… (null si el servidor no lo dijo).
  final EstadoCita? estadoAtencion;

  /// Por pagar en línea, por cobrar o pagada (null: no lleva cobro).
  final EstadoPagoCita? estadoPago;

  factory CitaTitular.desdeJson(Map<String, dynamic> json) => CitaTitular(
    reservaId: json['reserva_id'] as String,
    cliente: json['cliente'] as String?,
    estado: (json['estado'] ?? 'confirmada') as String,
    asistencia: json['asistencia'] as String?,
    retardo: (json['retardo'] ?? false) as bool,
    ordenId: json['orden_id'] as String?,
    estadoAtencion: EstadoCita.desde(json['estado_atencion']),
    estadoPago: EstadoPagoCita.desde(json['estado_pago']),
  );
}

/// Sesión de la agenda del staff (clase abierta o cita privada), en hora local. Lo
/// propio de cada tipo va en su bloque: `clase` (cupo, lista de espera) o `cita`
/// (a quién se atiende); el del otro tipo es null.
class SesionAgenda {
  const SesionAgenda({
    required this.id,
    required this.tipo,
    required this.oferta,
    required this.ofertaId,
    required this.instructor,
    required this.instructorId,
    required this.sala,
    this.sucursal,
    required this.iniciaEn,
    required this.terminaEn,
    required this.capacidad,
    required this.ocupados,
    required this.enEspera,
    required this.estado,
    this.precioMinor,
    this.clase,
    this.ocupacion,
    this.cita,
    this.marcadas = 0,
  });

  final String id;
  final TipoSesion tipo;
  final String? oferta;
  final String? ofertaId;
  final String? instructor;
  final String? instructorId;
  final String? sala;
  final String? sucursal;
  final DateTime iniciaEn;
  final DateTime terminaEn;
  final int? capacidad;
  final int ocupados;
  final int enEspera;

  /// A cuántos de los que ocupan lugar ya se les pasó lista.
  final int marcadas;
  final String estado;
  final int? precioMinor;

  /// Solo en clases: cupo, libres y lista de espera.
  final BloqueClase? clase;

  /// Solo en clases con cupo: la ocupación que se muestra.
  final Ocupacion? ocupacion;

  /// Solo en citas con cliente (sin titular: cancelada o aún sin cliente).
  final CitaTitular? cita;

  bool get esCita => tipo == TipoSesion.cita;
  bool get programada => estado == 'programada';
  int get duracionMin => terminaEn.difference(iniciaEn).inMinutes;

  /// Ya empezó y falta pasar lista (clase) o marcar si llegó (cita), igual que en
  /// el inicio web de quien imparte.
  bool faltaMarcar(DateTime ahora) {
    if (iniciaEn.isAfter(ahora)) {
      return false;
    }
    return switch (tipo) {
      TipoSesion.cita => cita != null && cita!.asistencia == null,
      TipoSesion.clase => ocupados > 0 && marcadas < ocupados,
    };
  }

  /// En qué va la cita, como lo calculó el servidor. Sin titular se lee por el
  /// estado de la sesión: cancelada, o aún sin cliente (null).
  EstadoCita? get estadoCita =>
      cita?.estadoAtencion ?? (programada ? null : EstadoCita.cancelada);

  /// ¿Falta cobrarla? (pendiente de pago en línea o agendada sin cobrar).
  bool get porCobrar {
    final c = cita;
    return c != null && c.ordenId != null && (c.estadoPago?.pendiente ?? false);
  }

  factory SesionAgenda.desdeJson(Map<String, dynamic> json) {
    final tipo = TipoSesion.desde(json['tipo']);
    final cita = json['cita'];
    return SesionAgenda(
      id: json['id'] as String,
      tipo: tipo,
      oferta: json['oferta'] as String?,
      ofertaId: json['oferta_id'] as String?,
      instructor: json['instructor'] as String?,
      instructorId: json['instructor_id'] as String?,
      sala: json['sala'] as String?,
      sucursal: json['sucursal'] as String?,
      iniciaEn: DateTime.parse(json['inicia_en'] as String).toLocal(),
      terminaEn: DateTime.parse(json['termina_en'] as String).toLocal(),
      capacidad: json['capacidad'] as int?,
      ocupados: (json['ocupados'] ?? 0) as int,
      enEspera: (json['en_espera'] ?? 0) as int,
      marcadas: (json['marcadas'] ?? 0) as int,
      estado: (json['estado'] ?? 'programada') as String,
      precioMinor: json['oferta_precio_clase'] as int?,
      clase: tipo == TipoSesion.clase
          ? BloqueClase.desdeJson(json['clase'], respaldo: json)
          : null,
      ocupacion: Ocupacion.desdeJson(json['ocupacion']),
      cita: tipo == TipoSesion.cita && cita is Map<String, dynamic>
          ? CitaTitular.desdeJson(cita)
          : null,
    );
  }
}

/// Profesional/instructor (columna de la agenda de citas).
class Profesional {
  const Profesional({required this.id, required this.nombre});

  final String id;
  final String nombre;

  String get iniciales {
    final partes = nombre
        .trim()
        .split(RegExp(r'\s+'))
        .where((p) => p.isNotEmpty)
        .take(2);
    return partes.map((p) => p[0].toUpperCase()).join();
  }

  factory Profesional.desdeJson(Map<String, dynamic> json) => Profesional(
    id: json['id'] as String,
    nombre: (json['nombre'] ?? '') as String,
  );
}

/// Asistente en la lista de una clase (roster) para el pase de lista.
class Asistente {
  const Asistente({
    required this.reservaId,
    required this.nombre,
    required this.estado,
    this.asistencia,
    this.retardo = false,
    this.automatica = false,
    this.primeraVez = false,
    this.adeudo = false,
  });

  final String reservaId;
  final String nombre;
  final String estado; // confirmada | ofrecida | en_espera | pendiente_pago
  final String? asistencia; // presente | ausente | null

  /// Llegó tarde (cuenta como que llegó) y si la marcó el sistema al terminar.
  final bool retardo;
  final bool automatica;
  final bool primeraVez;
  final bool adeudo;

  bool get enSala => estado != 'en_espera';
  bool get llego => asistencia == 'presente';

  /// Solo se pasa lista a quien tiene su lugar confirmado (no a una oferta de
  /// lugar sin aceptar ni a un pago pendiente): el servidor rechaza lo demás.
  bool get marcable => estado == 'confirmada';

  factory Asistente.desdeJson(Map<String, dynamic> json) => Asistente(
    reservaId: json['id'] as String,
    nombre: (json['persona'] ?? '—') as String,
    estado: (json['estado'] ?? 'confirmada') as String,
    asistencia: json['asistencia'] as String?,
    retardo: (json['retardo'] ?? false) as bool,
    automatica: (json['asistencia_automatica'] ?? false) as bool,
    primeraVez: (json['primera_vez'] ?? false) as bool,
    adeudo: (json['adeudo'] ?? false) as bool,
  );
}

/// La lista de una clase (GET /sesiones/{id}/reservas): quién reservó y desde cuándo
/// se pasa lista (ADR 0101). `empezo` lo dice el servidor (no el reloj del teléfono).
class ListaClase {
  const ListaClase({
    required this.asistentes,
    this.asistenciaDesde,
    this.empezo = false,
  });

  final List<Asistente> asistentes;
  final DateTime? asistenciaDesde;
  final bool empezo;

  /// ¿Ya se puede registrar la asistencia?
  bool abierta(DateTime ahora) =>
      asistenciaDesde == null || !ahora.isBefore(asistenciaDesde!);

  factory ListaClase.desdeJson(Map<String, dynamic> json) {
    final meta = (json['meta'] as Map<String, dynamic>?) ?? const {};
    final desde = meta['asistencia_desde'] as String?;
    return ListaClase(
      asistentes: ((json['data'] ?? []) as List)
          .map((e) => Asistente.desdeJson(e as Map<String, dynamic>))
          .toList(),
      asistenciaDesde: desde == null ? null : DateTime.parse(desde).toLocal(),
      empezo: (meta['empezo'] ?? false) as bool,
    );
  }
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
