import '../../../core/agenda/contrato_agenda.dart';
import '../../../core/hora_negocio.dart';

/// El día de hoy para el Inicio del negocio (GET /inicio/hoy): la agenda del día
/// con quién se espera, quién llegó y a quién falta pasar lista; y, si quien entra
/// tiene permiso, lo pendiente de cobro y las renovaciones por atender. Cada bloque
/// es null cuando no le toca verlo. En un negocio de citas trae además dónde hay
/// espacios libres hoy (ADR 0091). La modalidad del negocio no se lee de aquí: es
/// la de la sesión (ADR 0104).
class ResumenHoy {
  const ResumenHoy({
    required this.fecha,
    this.agenda,
    this.cobros,
    this.renovaciones,
    this.libres,
  });

  factory ResumenHoy.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) {
    final agenda = j['agenda'];
    final cobros = j['cobros'];
    final renovaciones = j['renovaciones'];
    final libres = j['libres'];
    return ResumenHoy(
      fecha: (j['fecha'] ?? '') as String,
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
          ? CobrosHoy.desdeJson(cobros, moneda: moneda)
          : null,
      renovaciones: renovaciones is Map<String, dynamic>
          ? RenovacionesHoy.desdeJson(renovaciones)
          : null,
    );
  }

  final String fecha;
  final AgendaHoy? agenda;
  final CobrosHoy? cobros;
  final RenovacionesHoy? renovaciones;

  /// Citas: quién tiene espacios libres hoy y desde qué hora (null en clases).
  final List<LibreHoy>? libres;

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
    this.sinRegistrar = 0,
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
      sinRegistrar: n('pendientes_registrar'),
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

  /// Citas: las que aún no terminan con alguien por llegar, las que terminaron sin
  /// registrar si vino (no son atención pendiente) y las por cobrar.
  final int porAtender;
  final int sinRegistrar;
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
    DateTime? iniciaLocal,
    this.desfaseMin,
    required this.esperados,
    required this.sinMarcar,
    required this.momento,
    this.oferta,
    this.instructor,
    this.sucursal,
    this.cliente,
  }) : iniciaLocal = iniciaLocal ?? iniciaEn;

  factory SesionHoy.desdeJson(Map<String, dynamic> j) {
    final inicia = DateTime.parse(j['inicia_en'] as String).toLocal();
    // La hora de la sucursal (`inicia_local`, sin zona) y su desfase
    // (`utc_offset_min`): la que se muestra, aunque el teléfono esté en otra. Como
    // en la agenda: sin desfase se deduce de la hora local; sin hora local se
    // calcula con el desfase; sin ninguno (API anterior), la del teléfono.
    final local = j['inicia_local'] is String
        ? HoraNegocio.pared(j['inicia_local'] as String)
        : null;
    final desfase =
        (j['utc_offset_min'] as num?)?.toInt() ??
        (local == null ? null : HoraNegocio.desfaseEntre(local, inicia));
    return SesionHoy(
      id: (j['id'] ?? '') as String,
      // Uno desconocido es un error (no se supone clase).
      tipo: TipoSesion.desde(j['tipo']),
      iniciaEn: inicia,
      iniciaLocal:
          local ??
          (desfase == null ? null : HoraNegocio.enDesfase(inicia, desfase)),
      desfaseMin: desfase,
      esperados: (j['esperados'] as num? ?? 0).toInt(),
      sinMarcar: (j['sin_marcar'] as num? ?? 0).toInt(),
      momento: (j['momento'] ?? 'proxima') as String,
      oferta: j['oferta'] as String?,
      instructor: j['instructor'] as String?,
      sucursal: j['sucursal'] as String?,
      cliente: j['cliente'] as String?,
    );
  }

  final String id;
  final TipoSesion tipo;
  final DateTime iniciaEn;

  /// La hora de la sucursal, para mostrarla.
  final DateTime iniciaLocal;

  /// Desfase con UTC (minutos) de la zona de su sucursal en ese momento; null si
  /// el API no lo manda.
  final int? desfaseMin;
  final int esperados;
  final int sinMarcar;

  /// proxima | en_curso | termino | cancelada
  final String momento;
  final String? oferta;
  final String? instructor;
  final String? sucursal;
  final String? cliente;

  /// "Pole Nivel 1" o, en una cita, "Corte · Dana".
  String get nombre => tipo == TipoSesion.cita && cliente != null
      ? '${oferta ?? ''} · $cliente'
      : (oferta ?? '—');

  /// ¿El teléfono está en otra zona que su sucursal? (con [desfaseTelefonoMin]; por
  /// omisión, el del teléfono cuando empieza). Sin desfase del API, no.
  bool enOtraZona([int? desfaseTelefonoMin]) =>
      desfaseMin != null &&
      desfaseMin !=
          (desfaseTelefonoMin ?? HoraNegocio.desfaseTelefono(iniciaEn));

  /// «Hora de Roma Norte» si el teléfono está en otra zona que su sucursal (como
  /// en la agenda); null si no.
  String? etiquetaHora([int? desfaseTelefonoMin]) =>
      HoraNegocio.etiqueta([if (enOtraZona(desfaseTelefonoMin)) sucursal]);
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
    // En la hora de la sede (`siguiente_local`, sin zona); sin ella (API
    // anterior), la del teléfono.
    siguiente:
        HoraNegocio.pared(j['siguiente_local'] as String?) ??
        HoraNegocio.pared(j['siguiente'] as String?),
  );

  final String profesional;
  final String? sucursal;
  final int huecos;

  /// Desde qué hora (de la sede) hay espacio.
  final DateTime? siguiente;
}

class CobrosHoy {
  const CobrosHoy({
    required this.ordenesPendientes,
    required this.porCobrarMinor,
    required this.enMora,
    this.moneda = 'MXN',
  });

  factory CobrosHoy.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) => CobrosHoy(
    ordenesPendientes: (j['ordenes_pendientes'] as num? ?? 0).toInt(),
    // Se suma en la moneda del negocio; nunca se mezclan monedas.
    porCobrarMinor: ((j['por_cobrar'] ?? const []) as List)
        .whereType<Map<String, dynamic>>()
        .where((m) => (m['moneda'] ?? moneda) == moneda)
        .fold<int>(0, (a, m) => a + (m['total_minor'] as num? ?? 0).toInt()),
    enMora: (j['en_mora'] as num? ?? 0).toInt(),
    moneda: moneda,
  );

  final int ordenesPendientes;
  final int porCobrarMinor;
  final int enMora;

  /// En la que se sumó lo por cobrar (la del negocio).
  final String moneda;
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
