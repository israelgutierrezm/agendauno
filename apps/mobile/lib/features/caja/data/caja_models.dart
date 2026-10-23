/// La caja de una sucursal (ADR 0175), como la da el API (`/caja`): su turno sin
/// cerrar, lo que se movió en él y, ya contada, lo esperado contra lo contado.
///
/// Todo el dinero en centavos (`*_minor`) con la moneda del turno: nunca double.
library;

/// Las formas de pago de la caja, en el orden de la web: el efectivo se cuenta; la
/// terminal, las transferencias y los depósitos se declaran contra su reporte.
abstract final class MetodosCaja {
  static const efectivo = 'efectivo';
  static const todos = ['efectivo', 'tarjeta', 'transferencia', 'ventanilla'];

  static String nombre(String metodo) => switch (metodo) {
    'efectivo' => 'Efectivo',
    'tarjeta' => 'Tarjeta (terminal)',
    'transferencia' => 'Transferencias',
    'ventanilla' => 'Depósitos en ventanilla',
    _ => metodo,
  };
}

/// Un renglón del libro del turno. A ciegas (quien solo opera, con la caja
/// abierta) el API solo manda los retiros e ingresos de efectivo.
class MovimientoCaja {
  const MovimientoCaja({
    required this.id,
    required this.tipo,
    required this.metodo,
    required this.montoMinor,
    this.motivo,
    this.quien,
    this.fecha,
  });

  final String id;

  /// cobro, venta, propina, devolucion, anulacion, correccion, retiro o ingreso.
  final String tipo;
  final String metodo;

  /// Con signo: lo que sale de la caja es negativo.
  final int montoMinor;
  final String? motivo;
  final String? quien;
  final String? fecha;

  String get tipoTexto => switch (tipo) {
    'cobro' => 'Cobro',
    'venta' => 'Venta',
    'propina' => 'Propina',
    'devolucion' => 'Devolución',
    'anulacion' => 'Anulación',
    'correccion' => 'Corrección de forma de pago',
    'retiro' => 'Retiro',
    'ingreso' => 'Ingreso',
    _ => tipo,
  };

  factory MovimientoCaja.desdeJson(Map<String, dynamic> j) => MovimientoCaja(
    id: (j['id'] ?? '') as String,
    tipo: (j['tipo'] ?? '') as String,
    metodo: (j['metodo'] ?? MetodosCaja.efectivo) as String,
    montoMinor: (j['monto_minor'] as num? ?? 0).toInt(),
    motivo: j['motivo'] as String?,
    quien: j['quien'] as String?,
    fecha: j['fecha'] as String?,
  );
}

/// Lo esperado, lo contado y la diferencia de una forma de pago (se ve ya contada).
class ConteoCaja {
  const ConteoCaja({
    required this.metodo,
    required this.esperadoMinor,
    required this.contadoMinor,
    required this.diferenciaMinor,
  });

  final String metodo;
  final int esperadoMinor;
  final int contadoMinor;
  final int diferenciaMinor;

  factory ConteoCaja.desdeJson(Map<String, dynamic> j) => ConteoCaja(
    metodo: (j['metodo'] ?? '') as String,
    esperadoMinor: (j['esperado_minor'] as num? ?? 0).toInt(),
    contadoMinor: (j['contado_minor'] as num? ?? 0).toInt(),
    diferenciaMinor: (j['diferencia_minor'] as num? ?? 0).toInt(),
  );
}

/// El turno de caja de una sede: de su apertura (con el fondo) a su cierre.
class TurnoCaja {
  const TurnoCaja({
    required this.id,
    required this.estado,
    required this.moneda,
    required this.fondoMinor,
    this.sucursal,
    this.abiertaPor,
    this.abiertaEn,
    this.notaApertura,
    this.cerradaPor,
    this.cerradaEn,
    this.diferenciaMinor,
    this.motivoDiferencia,
    this.requiereRevision = false,
    this.esperado,
    this.conteos = const [],
    this.movimientos = const [],
  });

  final String id;

  /// abierta, contada (no cuadró: falta el motivo) o cerrada.
  final String estado;
  final String moneda;
  final int fondoMinor;
  final String? sucursal;
  final String? abiertaPor;
  final String? abiertaEn;
  final String? notaApertura;
  final String? cerradaPor;
  final String? cerradaEn;

  /// La diferencia neta del conteo; null a ciegas (abierta, para quien solo opera).
  final int? diferenciaMinor;
  final String? motivoDiferencia;
  final bool requiereRevision;

  /// Lo esperado ahora de cada forma de pago: solo para quien supervisa y con la
  /// caja abierta. A ciegas, null (no se muestra antes de contar).
  final Map<String, int>? esperado;
  final List<ConteoCaja> conteos;
  final List<MovimientoCaja> movimientos;

  bool get abierta => estado == 'abierta';
  bool get contada => estado == 'contada';
  bool get cerrada => estado == 'cerrada';

  factory TurnoCaja.desdeJson(Map<String, dynamic> j) {
    final sucursal = j['sucursal'];
    final esperado = j['esperado'];
    return TurnoCaja(
      id: (j['id'] ?? '') as String,
      estado: (j['estado'] ?? '') as String,
      moneda: (j['moneda'] ?? 'MXN') as String,
      fondoMinor: (j['fondo_minor'] as num? ?? 0).toInt(),
      sucursal: sucursal is Map ? sucursal['nombre'] as String? : null,
      abiertaPor: j['abierta_por'] as String?,
      abiertaEn: j['abierta_en'] as String?,
      notaApertura: j['nota_apertura'] as String?,
      cerradaPor: j['cerrada_por'] as String?,
      cerradaEn: j['cerrada_en'] as String?,
      diferenciaMinor: (j['diferencia_minor'] as num?)?.toInt(),
      motivoDiferencia: j['motivo_diferencia'] as String?,
      requiereRevision: j['requiere_revision'] == true,
      esperado: esperado is Map
          ? {
              for (final m in MetodosCaja.todos)
                m: (esperado[m] as num? ?? 0).toInt(),
            }
          : null,
      conteos: ((j['conteos'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(ConteoCaja.desdeJson)
          .toList(),
      movimientos: ((j['movimientos'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(MovimientoCaja.desdeJson)
          .toList(),
    );
  }
}

/// La caja de una sede (GET /caja): si ya usa caja y su turno sin cerrar (o null).
class CajaSede {
  const CajaSede({required this.usaCaja, this.turno});

  /// ¿La sede ya abrió alguna caja? Desde entonces, cobrar en su mostrador pide
  /// la caja abierta.
  final bool usaCaja;
  final TurnoCaja? turno;

  factory CajaSede.desdeJson(Map<String, dynamic> j) {
    final turno = j['turno'];
    return CajaSede(
      usaCaja: j['usa_caja'] == true,
      turno: turno is Map<String, dynamic> ? TurnoCaja.desdeJson(turno) : null,
    );
  }
}

/// Lo que dejó el conteo: el turno (cerrado si cuadró, «contado» si no) y si cuadró.
class ResultadoConteo {
  const ResultadoConteo({required this.turno, required this.cuadra});

  final TurnoCaja turno;
  final bool cuadra;
}
