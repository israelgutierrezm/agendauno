/// Modelos del autoservicio del miembro (Mi cuenta).
class DerechoMiembro {
  const DerechoMiembro({
    required this.ilimitado,
    this.saldo,
    this.disponible,
    this.producto,
    this.pausaHasta,
  });

  final bool ilimitado;
  final int? saldo;
  final int? disponible;
  final String? producto;

  /// Último día en pausa (AAAA-MM-DD) si la membresía está congelada.
  final String? pausaHasta;

  /// Créditos disponibles (1 crédito = 1000 unidades).
  int get creditosDisponibles => ((disponible ?? 0) / 1000).round();

  factory DerechoMiembro.desdeJson(Map<String, dynamic> j) => DerechoMiembro(
    ilimitado: (j['ilimitado'] ?? false) as bool,
    saldo: j['saldo'] as int?,
    disponible: j['disponible'] as int?,
    producto: j['producto'] as String?,
    pausaHasta: j['pausa_hasta'] as String?,
  );
}

class ReservaMiembro {
  const ReservaMiembro({
    required this.id,
    required this.estado,
    this.sesionId,
    this.oferta,
    this.sucursal,
    this.iniciaEn,
    this.zonaHoraria,
    this.ofertaExpiraEn,
    this.ordenId,
  });

  final String id;
  final String estado;
  final String? sesionId;
  final String? oferta;
  final String? sucursal;
  final String? iniciaEn;
  final String? zonaHoraria;
  // Hasta cuándo puede aceptar el lugar que le ofreció la lista de espera.
  final String? ofertaExpiraEn;
  // Orden a pagar para confirmar una cita apartada.
  final String? ordenId;

  bool get ofrecida => estado == 'ofrecida';

  String get estadoTexto => switch (estado) {
    'confirmada' => 'Confirmada',
    'en_espera' => 'En lista de espera',
    'ofrecida' => 'Lugar disponible',
    'pendiente_pago' => 'Pendiente de pago',
    _ => estado,
  };

  factory ReservaMiembro.desdeJson(Map<String, dynamic> j) => ReservaMiembro(
    id: (j['id'] ?? '') as String,
    estado: (j['estado'] ?? '') as String,
    sesionId: j['sesion_id'] as String?,
    oferta: j['oferta'] as String?,
    sucursal: j['sucursal'] as String?,
    iniciaEn: j['inicia_en'] as String?,
    zonaHoraria: j['zona_horaria'] as String?,
    ofertaExpiraEn: j['oferta_expira_en'] as String?,
    ordenId: j['orden_id'] as String?,
  );
}

class ClaseMiembro {
  const ClaseMiembro({
    required this.id,
    this.oferta,
    this.sucursal,
    this.iniciaEn,
    this.zonaHoraria,
    this.capacidad,
    this.ocupados = 0,
  });

  final String id;
  final String? oferta;
  final String? sucursal;
  final String? iniciaEn;
  final String? zonaHoraria;
  final int? capacidad;
  final int ocupados;

  /// Sin lugares: se ofrece anotarse en la lista de espera.
  bool get llena => capacidad != null && ocupados >= capacidad!;

  factory ClaseMiembro.desdeJson(Map<String, dynamic> j) => ClaseMiembro(
    id: (j['id'] ?? '') as String,
    oferta: j['oferta'] as String?,
    sucursal: j['sucursal'] as String?,
    iniciaEn: j['inicia_en'] as String?,
    zonaHoraria: j['zona_horaria'] as String?,
    capacidad: j['capacidad'] as int?,
    ocupados: (j['ocupados'] ?? 0) as int,
  );
}

/// Consentimiento (carta responsiva, reglamento) que el negocio pide firmar.
class ConsentimientoPendiente {
  const ConsentimientoPendiente({
    required this.id,
    required this.titulo,
    required this.contenido,
    this.version = 1,
  });

  final String id;
  final String titulo;
  final String contenido;
  final int version;

  factory ConsentimientoPendiente.desdeJson(Map<String, dynamic> j) =>
      ConsentimientoPendiente(
        id: (j['id'] ?? '') as String,
        titulo: (j['titulo'] ?? '') as String,
        contenido: (j['contenido'] ?? '') as String,
        version: (j['version'] ?? 1) as int,
      );
}

/// Opciones para agendar una cita desde la cuenta.
class OpcionCita {
  const OpcionCita({
    required this.id,
    required this.nombre,
    this.duracionMinutos,
    this.zonaHoraria,
  });

  final String id;
  final String nombre;
  final int? duracionMinutos;
  final String? zonaHoraria;

  factory OpcionCita.desdeJson(Map<String, dynamic> j) => OpcionCita(
    id: (j['id'] ?? '') as String,
    nombre: (j['nombre'] ?? '') as String,
    duracionMinutos: j['duracion_minutos'] as int?,
    zonaHoraria: j['zona_horaria'] as String?,
  );
}

class OpcionesCita {
  const OpcionesCita({
    required this.servicios,
    required this.sucursales,
    required this.profesionales,
  });

  final List<OpcionCita> servicios;
  final List<OpcionCita> sucursales;
  final List<OpcionCita> profesionales;
}

/// Estado agregado de la pantalla Mi cuenta.
class MiCuenta {
  const MiCuenta({
    required this.derechos,
    required this.reservas,
    required this.clases,
    this.consentimientos = const [],
    this.pagoEnLinea = false,
  });

  /// ¿El negocio cobra en línea? Entonces puede pagar aquí lo pendiente.
  final bool pagoEnLinea;
  final List<DerechoMiembro> derechos;
  final List<ReservaMiembro> reservas;
  final List<ClaseMiembro> clases;
  final List<ConsentimientoPendiente> consentimientos;

  /// Las clases que ya reservó (o en las que espera), para no ofrecerlas de nuevo.
  Set<String> get sesionesReservadas =>
      reservas.map((r) => r.sesionId).whereType<String>().toSet();
}
