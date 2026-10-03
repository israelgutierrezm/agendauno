/// El día de hoy para el Inicio del negocio (GET /inicio/hoy): la agenda del día
/// con quién se espera, quién llegó y a quién falta pasar lista; y, si quien entra
/// tiene permiso, lo pendiente de cobro y las renovaciones por atender. Cada bloque
/// es null cuando no le toca verlo. En un negocio de citas trae además dónde hay
/// espacios libres hoy (ADR 0091).
class ResumenHoy {
  const ResumenHoy({
    required this.fecha,
    this.modalidad,
    this.agenda,
    this.cobros,
    this.renovaciones,
    this.libres,
  });

  factory ResumenHoy.desdeJson(Map<String, dynamic> j) {
    final agenda = j['agenda'];
    final cobros = j['cobros'];
    final renovaciones = j['renovaciones'];
    final libres = j['libres'];
    return ResumenHoy(
      fecha: (j['fecha'] ?? '') as String,
      modalidad: j['modalidad'] as String?,
      libres: libres is List
          ? libres
                .whereType<Map<String, dynamic>>()
                .map(LibreHoy.desdeJson)
                .toList()
          : null,
      agenda: agenda is Map<String, dynamic>
          ? AgendaHoy.desdeJson(agenda)
          : null,
      cobros: cobros is Map<String, dynamic>
          ? CobrosHoy.desdeJson(cobros)
          : null,
      renovaciones: renovaciones is Map<String, dynamic>
          ? RenovacionesHoy.desdeJson(renovaciones)
          : null,
    );
  }

  final String fecha;

  /// 'citas' o 'clases' (null si el API no lo dice).
  final String? modalidad;
  final AgendaHoy? agenda;
  final CobrosHoy? cobros;
  final RenovacionesHoy? renovaciones;

  /// Citas: quién tiene espacios libres hoy y desde qué hora (null en clases).
  final List<LibreHoy>? libres;

  bool get esCitas => modalidad == 'citas';

  /// ¿Hay algo que cobrar o renovar?
  bool get hayPendientes =>
      (cobros != null &&
          (cobros!.ordenesPendientes > 0 || cobros!.enMora > 0)) ||
      (renovaciones != null &&
          (renovaciones!.porVencer > 0 || renovaciones!.vencidas > 0));
}

class AgendaHoy {
  const AgendaHoy({
    required this.sesiones,
    required this.esperados,
    required this.llegaron,
    required this.sinMarcar,
    required this.lista,
    this.capacidad = 0,
    this.listasPendientes = 0,
    this.enEspera = 0,
    this.porAtender = 0,
    this.porCobrar = 0,
  });

  factory AgendaHoy.desdeJson(Map<String, dynamic> j) {
    final t = (j['totales'] ?? const {}) as Map<String, dynamic>;
    int n(String k) => (t[k] as num? ?? 0).toInt();
    return AgendaHoy(
      sesiones: n('sesiones'),
      esperados: n('esperados'),
      llegaron: n('llegaron'),
      sinMarcar: n('sin_marcar'),
      capacidad: n('capacidad'),
      listasPendientes: n('listas_pendientes'),
      enEspera: n('en_espera'),
      porAtender: n('por_atender'),
      porCobrar: n('por_cobrar'),
      lista: ((j['sesiones'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(SesionHoy.desdeJson)
          .toList(),
    );
  }

  /// Cuántas clases o citas hay hoy (sin las canceladas).
  final int sesiones;
  final int esperados;
  final int llegaron;
  final int sinMarcar;
  final List<SesionHoy> lista;

  /// Clases: lugares en total, listas por registrar y quién espera lugar.
  final int capacidad;
  final int listasPendientes;
  final int enEspera;

  /// Citas: las que aún no terminan con alguien por llegar, y las por cobrar.
  final int porAtender;
  final int porCobrar;

  /// Lo que está en curso, si hay.
  SesionHoy? get enCurso =>
      lista.where((s) => s.momento == 'en_curso').firstOrNull;

  /// Lo que está en curso o lo siguiente de hoy (sin las canceladas).
  SesionHoy? get siguiente =>
      enCurso ?? lista.where((s) => s.momento == 'proxima').firstOrNull;
}

class SesionHoy {
  const SesionHoy({
    required this.id,
    required this.tipo,
    required this.iniciaEn,
    required this.esperados,
    required this.sinMarcar,
    required this.momento,
    this.oferta,
    this.instructor,
    this.sucursal,
    this.cliente,
  });

  factory SesionHoy.desdeJson(Map<String, dynamic> j) => SesionHoy(
    id: (j['id'] ?? '') as String,
    tipo: (j['tipo'] ?? 'clase') as String,
    iniciaEn: DateTime.parse(j['inicia_en'] as String).toLocal(),
    esperados: (j['esperados'] as num? ?? 0).toInt(),
    sinMarcar: (j['sin_marcar'] as num? ?? 0).toInt(),
    momento: (j['momento'] ?? 'proxima') as String,
    oferta: j['oferta'] as String?,
    instructor: j['instructor'] as String?,
    sucursal: j['sucursal'] as String?,
    cliente: j['cliente'] as String?,
  );

  final String id;
  final String tipo;
  final DateTime iniciaEn;
  final int esperados;
  final int sinMarcar;

  /// proxima | en_curso | termino | cancelada
  final String momento;
  final String? oferta;
  final String? instructor;
  final String? sucursal;
  final String? cliente;

  /// "Pole Nivel 1" o, en una cita, "Corte · Dana".
  String get nombre => tipo == 'cita' && cliente != null
      ? '${oferta ?? ''} · $cliente'
      : (oferta ?? '—');
}

/// Espacios libres de un profesional en una sede hoy (citas).
class LibreHoy {
  const LibreHoy({
    required this.profesional,
    required this.huecos,
    this.sucursal,
    this.siguiente,
  });

  factory LibreHoy.desdeJson(Map<String, dynamic> j) => LibreHoy(
    profesional: (j['profesional'] ?? '') as String,
    sucursal: j['sucursal'] as String?,
    huecos: (j['huecos'] as num? ?? 0).toInt(),
    siguiente: DateTime.tryParse((j['siguiente'] ?? '') as String)?.toLocal(),
  );

  final String profesional;
  final String? sucursal;
  final int huecos;

  /// Desde qué hora (local) hay espacio.
  final DateTime? siguiente;
}

class CobrosHoy {
  const CobrosHoy({
    required this.ordenesPendientes,
    required this.porCobrarMinor,
    required this.enMora,
  });

  factory CobrosHoy.desdeJson(Map<String, dynamic> j) => CobrosHoy(
    ordenesPendientes: (j['ordenes_pendientes'] as num? ?? 0).toInt(),
    // Los montos de la app son en pesos (MXN); otras monedas se suman aparte.
    porCobrarMinor: ((j['por_cobrar'] ?? const []) as List)
        .whereType<Map<String, dynamic>>()
        .where((m) => (m['moneda'] ?? 'MXN') == 'MXN')
        .fold<int>(0, (a, m) => a + (m['total_minor'] as num? ?? 0).toInt()),
    enMora: (j['en_mora'] as num? ?? 0).toInt(),
  );

  final int ordenesPendientes;
  final int porCobrarMinor;
  final int enMora;
}

class RenovacionesHoy {
  const RenovacionesHoy({
    required this.porVencer,
    required this.vencidas,
    required this.dias,
  });

  factory RenovacionesHoy.desdeJson(Map<String, dynamic> j) => RenovacionesHoy(
    porVencer: (j['por_vencer'] as num? ?? 0).toInt(),
    vencidas: (j['vencidas'] as num? ?? 0).toInt(),
    dias: (j['dias'] as num? ?? 0).toInt(),
  );

  final int porVencer;
  final int vencidas;
  final int dias;
}
