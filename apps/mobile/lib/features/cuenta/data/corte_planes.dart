import '../../auth/data/sesion.dart';
import 'cuenta_models.dart';

/// Corte de planes (ADR 0050): por cada paquete o membresía, qué incluía, sus
/// clases extra, en qué clases se usó y lo que le queda. Viene de `GET /mi/planes`.
class UsoPlan {
  const UsoPlan({
    required this.iniciaEn,
    required this.estado,
    this.clase,
    this.extra = false,
  });

  final String? clase;
  final DateTime iniciaEn;

  /// asistio | no_asistio | cancelacion_tardia | proxima | tomada.
  final String estado;
  final bool extra;

  String get estadoTexto => switch (estado) {
    'asistio' => 'Asistió',
    'no_asistio' => 'No asistió',
    'cancelacion_tardia' => 'Cancelación tardía',
    'proxima' => 'Próxima',
    _ => 'Tomada',
  };

  /// No asistió o canceló tarde: lo que se le cobró sin usarlo.
  bool get pideAtencion =>
      estado == 'no_asistio' || estado == 'cancelacion_tardia';

  factory UsoPlan.desdeJson(Map<String, dynamic> j) => UsoPlan(
    clase: j['clase'] as String?,
    iniciaEn: DateTime.parse(j['inicia_en'] as String).toLocal(),
    estado: (j['estado'] ?? 'tomada') as String,
    extra: (j['extra'] ?? false) as bool,
  );
}

class ExtraPlan {
  const ExtraPlan({
    required this.unidades,
    required this.usadas,
    this.producto,
    this.comprado,
  });

  final String? producto;
  final String? comprado;
  final int unidades;
  final int usadas;

  factory ExtraPlan.desdeJson(Map<String, dynamic> j) => ExtraPlan(
    producto: j['producto'] as String?,
    comprado: j['comprado'] as String?,
    unidades: (j['unidades'] ?? 0) as int,
    usadas: (j['usadas'] ?? 0) as int,
  );
}

class PlanCorte {
  const PlanCorte({
    required this.id,
    required this.derechoId,
    required this.estado,
    required this.ilimitado,
    required this.unidades,
    this.producto,
    this.desde,
    this.hasta,
    this.aplicaA = const [],
    this.extras = const [],
    this.usos = const [],
    this.cobertura = const CoberturaSucursales(),
  });

  final String id;
  final String derechoId;
  final String? producto;
  final String? desde;
  final String? hasta;

  /// En qué sucursales vale (se dice solo si no es en todas).
  final CoberturaSucursales cobertura;

  /// vigente | por_empezar | vencido | agotado | pausado | suspendido | cancelado.
  final String estado;
  final bool ilimitado;

  /// Clases a las que aplica; vacío = a todas.
  final List<String> aplicaA;

  /// incluidas, extras, usadas, devueltas, vencidas, ajustes, apartadas,
  /// disponibles (1000 unidades = 1 clase).
  final Map<String, int> unidades;
  final List<ExtraPlan> extras;
  final List<UsoPlan> usos;

  /// Vigente, por empezar o en pausa: los que siguen en juego.
  bool get actual => const [
    'vigente',
    'por_empezar',
    'pausado',
    'suspendido',
  ].contains(estado);

  String get estadoTexto => estadoTextoCon(const Terminologia());

  /// El estado con la terminología del negocio: agotado es «Sin citas» en una
  /// barbería y «Sin lecciones» en una escuela de natación.
  String estadoTextoCon(Terminologia t) => switch (estado) {
    'vigente' => 'Vigente',
    'por_empezar' => 'Por empezar',
    'vencido' => 'Vencido',
    'agotado' => 'Sin ${t.sesiones.toLowerCase()}',
    'pausado' => 'En pausa',
    'suspendido' => 'Suspendido',
    'cancelado' => 'Cancelado',
    _ => estado,
  };

  /// "3" o "1.5" clases a partir de unidades.
  static String clases(int unidades) {
    final n = unidades / 1000;
    return n == n.roundToDouble() ? n.round().toString() : n.toStringAsFixed(1);
  }

  /// Los números que se muestran: siempre incluidas, usadas y disponibles (si no
  /// es ilimitado); los demás solo si hay algo.
  List<(String, String)> get numeros {
    int u(String k) => unidades[k] ?? 0;
    return [
      if (!ilimitado) ('Incluidas', clases(u('incluidas'))),
      if (u('extras') > 0) ('Extras', clases(u('extras'))),
      ('Usadas', clases(u('usadas'))),
      if (u('apartadas') > 0) ('Reservadas', clases(u('apartadas'))),
      if (u('devueltas') > 0) ('Devueltas', clases(u('devueltas'))),
      if (u('vencidas') > 0) ('Vencidas', clases(u('vencidas'))),
      if (!ilimitado) ('Disponibles', clases(u('disponibles'))),
    ];
  }

  factory PlanCorte.desdeJson(Map<String, dynamic> j) => PlanCorte(
    id: (j['id'] ?? '') as String,
    derechoId: (j['derecho_id'] ?? '') as String,
    producto: j['producto'] as String?,
    desde: j['desde'] as String?,
    hasta: j['hasta'] as String?,
    cobertura: CoberturaSucursales.desdeJson(j),
    estado: (j['estado'] ?? 'vigente') as String,
    ilimitado: (j['ilimitado'] ?? false) as bool,
    aplicaA: ((j['aplica_a'] ?? []) as List).whereType<String>().toList(),
    unidades: ((j['unidades'] ?? {}) as Map<String, dynamic>).map(
      (k, v) => MapEntry(k, (v ?? 0) as int),
    ),
    extras: ((j['extras'] ?? []) as List)
        .whereType<Map<String, dynamic>>()
        .map(ExtraPlan.desdeJson)
        .toList(),
    usos: ((j['usos'] ?? []) as List)
        .whereType<Map<String, dynamic>>()
        .map(UsoPlan.desdeJson)
        .toList(),
  );
}
