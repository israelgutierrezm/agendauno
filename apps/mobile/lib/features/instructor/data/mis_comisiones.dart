import '../../../core/formato.dart';

/// Una fila de «Mis comisiones» (`GET /mis-comisiones`, ADR 0219): lo de su
/// esquema de pago con una tarifa del periodo (lo ya atendido y lo que viene) o sus
/// propinas (`tipo` = `propinas`).
class FilaComision {
  const FilaComision({
    required this.tipo,
    this.porcentajeBp,
    required this.unidades,
    required this.montoTotalMinor,
    required this.porVenirMinor,
    this.moneda = 'MXN',
  });

  factory FilaComision.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) => FilaComision(
    tipo: (j['tipo'] ?? '') as String,
    porcentajeBp: (j['porcentaje_bp'] as num?)?.toInt(),
    unidades: (j['unidades'] as num?) ?? 0,
    montoTotalMinor: (j['monto_total_minor'] as num?)?.toInt() ?? 0,
    porVenirMinor: (j['por_venir_minor'] as num?)?.toInt() ?? 0,
    moneda: Formato.moneda(j['moneda'], moneda),
  );

  /// `porcentaje_venta`, `por_clase`, `por_asistente`, `por_hora` o `propinas`.
  final String tipo;

  /// En puntos base (4000 = 40 %); solo en `porcentaje_venta`.
  final int? porcentajeBp;

  /// Lo que se contó: citas, personas o, por hora, horas (con decimales).
  final num unidades;
  final int montoTotalMinor;
  final int porVenirMinor;
  final String moneda;

  bool get esPropina => tipo == 'propinas';
}

/// Lo que lleva ganado quien atiende en un periodo: su comisión, sus propinas y lo
/// de sus citas que aún no atiende. Solo lo suyo; lo paga el negocio en su corte
/// de nómina. Una sola moneda por negocio (ADR 0099).
class MisComisiones {
  const MisComisiones(this.filas, {this.moneda = 'MXN'});

  /// [data] es la lista de `data`; sin filas, la moneda del negocio ([moneda]).
  factory MisComisiones.desdeJson(Object? data, {String moneda = 'MXN'}) {
    final filas = data is List
        ? data
              .whereType<Map<String, dynamic>>()
              .map((j) => FilaComision.desdeJson(j, moneda: moneda))
              .toList()
        : const <FilaComision>[];
    return MisComisiones(
      filas,
      moneda: filas.isEmpty ? moneda : filas.first.moneda,
    );
  }

  final List<FilaComision> filas;
  final String moneda;

  bool get vacio => filas.isEmpty;

  Iterable<FilaComision> get _deSuEsquema => filas.where((f) => !f.esPropina);

  int get comisionMinor =>
      _deSuEsquema.fold(0, (suma, f) => suma + f.montoTotalMinor);

  int get propinasMinor => filas
      .where((f) => f.esPropina)
      .fold(0, (suma, f) => suma + f.montoTotalMinor);

  /// Lo ganado: su comisión más sus propinas.
  int get ganadoMinor => comisionMinor + propinasMinor;

  /// Lo de sus citas agendadas que aún no atiende.
  int get porVenirMinor => filas.fold(0, (suma, f) => suma + f.porVenirMinor);

  /// ¿Su esquema cuenta citas o personas? (por hora cuenta horas: no se dice
  /// cuántas atendió).
  bool get cuentaAtendidas => _deSuEsquema.any((f) => f.tipo != 'por_hora');

  /// Cuántas atendió en el periodo (lo que contó su esquema, sin las horas).
  int get atendidas => _deSuEsquema
      .where((f) => f.tipo != 'por_hora')
      .fold(0, (suma, f) => suma + f.unidades.round());

  /// «40 % de lo cobrado» o «Tu esquema de pago» (la tarifa más reciente del
  /// periodo); null si solo hay propinas.
  String? get esquema {
    final f = _deSuEsquema.lastOrNull;
    if (f == null) {
      return null;
    }
    final bp = f.porcentajeBp;
    return f.tipo == 'porcentaje_venta' && bp != null && bp > 0
        ? '${porcentaje(bp)} % de lo cobrado'
        : 'Tu esquema de pago';
  }

  /// "40", "12.5" o "12.34" a partir de puntos base, sin punto flotante.
  static String porcentaje(int bp) {
    final resto = bp % 100;
    return resto == 0
        ? '${bp ~/ 100}'
        : '${bp ~/ 100}.${resto.toString().padLeft(2, '0').replaceFirst(RegExp(r'0+$'), '')}';
  }
}
