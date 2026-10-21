import '../../../core/formato.dart';

/// Un presupuesto que el negocio le mandó (solo negocios de citas), como lo dan
/// `GET /mi/presupuestos` y `/mi/presupuestos/{id}`: sus conceptos, los totales con
/// sus impuestos y lo que puede hacer con él (aceptarlo o rechazarlo mientras está
/// vigente, pagarlo en línea y agendar sus servicios). El dinero llega en centavos
/// (`*_minor`) con su moneda; nunca con punto flotante.
class PresupuestoCliente {
  const PresupuestoCliente({
    required this.id,
    required this.folio,
    required this.estado,
    required this.validoHasta,
    required this.revision,
    required this.moneda,
    required this.totalMinor,
    this.vencido = false,
    this.cliente,
    this.sucursal,
    this.impuestos = 'incluidos',
    this.tasaImpuesto = '',
    this.subtotalMinor = 0,
    this.descuentoMinor = 0,
    this.impuestoMinor = 0,
    this.notas,
    this.respondidoEn,
    this.respondidoPor,
    this.lineas = const [],
    this.servicios = const [],
    this.puedeResponder = false,
    this.orden,
    this.puedePagar = false,
  });

  final String id;
  final String folio;

  /// enviado, aceptado, rechazado o convertido (aceptado y vuelto su cobro).
  final String estado;
  final bool vencido;

  /// Hasta qué día vale (AAAA-MM-DD, día del negocio).
  final String validoHasta;

  /// La versión que vio: aceptarlo con otra (el negocio lo cambió) no procede.
  final int revision;
  final String? cliente;
  final ({String id, String nombre})? sucursal;
  final String moneda;

  /// `incluidos` (en los precios) o `desglosados` (se suman aparte).
  final String impuestos;

  /// La tasa ya en texto («16 %»).
  final String tasaImpuesto;
  final int subtotalMinor;
  final int descuentoMinor;
  final int impuestoMinor;
  final int totalMinor;
  final String? notas;
  final String? respondidoEn;

  /// `cliente` o `negocio`.
  final String? respondidoPor;
  final List<LineaPresupuesto> lineas;

  /// Los servicios que puede agendar (su id público y su nombre).
  final List<({String id, String nombre})> servicios;
  final bool puedeResponder;

  /// Su cobro al aceptarlo: pendiente, pagada o cancelada.
  final ({String id, String estado})? orden;

  /// El negocio cobra en línea y tiene algo por pagar.
  final bool puedePagar;

  bool get impuestosDesglosados => impuestos == 'desglosados';

  /// Lo aceptó (y ya es su cobro con el negocio).
  bool get aceptado => estado == 'aceptado' || estado == 'convertido';

  /// Su cobro sigue pendiente: se paga en línea o en el negocio.
  bool get porPagar => orden?.estado == 'pendiente';

  /// Ya aceptado, sus servicios se agendan aparte.
  bool get puedeAgendar => aceptado && servicios.isNotEmpty;

  /// Venció sin que lo respondiera.
  bool get vencioSinResponder => vencido && estado == 'enviado';

  /// «Vale hasta el 24 de octubre».
  String get vigencia => 'Vale hasta el ${Formato.fechaLarga(validoHasta)}';

  /// Cómo está, como en la web.
  SituacionPresupuesto get situacion {
    if (estado == 'rechazado') {
      return SituacionPresupuesto.rechazado;
    }
    if (orden?.estado == 'pagada') {
      return SituacionPresupuesto.pagado;
    }
    if (aceptado) {
      return SituacionPresupuesto.aceptado;
    }
    if (vencido) {
      return SituacionPresupuesto.vencido;
    }
    return SituacionPresupuesto.porResponder;
  }

  /// La clave del pago en línea (como la web): reintentar el mismo cobro devuelve
  /// el pago que ya se inició en vez de crear otro.
  String get clavePago => 'presupuesto-$id-${orden?.id ?? ''}';

  /// [moneda]: la del negocio, si el presupuesto no trae la suya.
  factory PresupuestoCliente.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) {
    int minor(String clave) => (j[clave] as num? ?? 0).toInt();
    final sucursal = j['sucursal'];
    final orden = j['orden'];
    final notas = (j['notas'] as String?)?.trim();
    return PresupuestoCliente(
      id: (j['id'] ?? '') as String,
      folio: (j['folio'] ?? '') as String,
      estado: (j['estado'] ?? 'enviado') as String,
      vencido: j['vencido'] == true,
      validoHasta: (j['valido_hasta'] ?? '') as String,
      revision: (j['revision'] as num? ?? 1).toInt(),
      cliente: j['cliente'] as String?,
      sucursal: sucursal is Map<String, dynamic>
          ? (
              id: (sucursal['id'] ?? '') as String,
              nombre: (sucursal['nombre'] ?? '') as String,
            )
          : null,
      moneda: Formato.moneda(j['moneda'], moneda),
      impuestos: (j['impuestos'] ?? 'incluidos') as String,
      tasaImpuesto: (j['tasa_impuesto'] ?? '') as String,
      subtotalMinor: minor('subtotal_minor'),
      descuentoMinor: minor('descuento_minor'),
      impuestoMinor: minor('impuesto_minor'),
      totalMinor: minor('total_minor'),
      notas: notas == null || notas.isEmpty ? null : notas,
      respondidoEn: j['respondido_en'] as String?,
      respondidoPor: j['respondido_por'] as String?,
      lineas: ((j['lineas'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(LineaPresupuesto.desdeJson)
          .toList(),
      servicios: ((j['servicios'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(
            (s) => (
              id: (s['id'] ?? '') as String,
              nombre: (s['nombre'] ?? '') as String,
            ),
          )
          .where((s) => s.id.isNotEmpty)
          .toList(),
      puedeResponder: j['puede_responder'] == true,
      orden: orden is Map<String, dynamic>
          ? (
              id: (orden['id'] ?? '') as String,
              estado: (orden['estado'] ?? '') as String,
            )
          : null,
      puedePagar: j['puede_pagar'] == true,
    );
  }
}

/// Un concepto del presupuesto: qué, cuántos y cuánto (con su descuento).
class LineaPresupuesto {
  const LineaPresupuesto({
    required this.descripcion,
    required this.cantidad,
    required this.subtotalMinor,
    this.tipo = '',
    this.precioUnitarioMinor = 0,
    this.descuentoMinor = 0,
  });

  /// servicio, producto o libre.
  final String tipo;
  final String descripcion;
  final int cantidad;
  final int precioUnitarioMinor;
  final int descuentoMinor;
  final int subtotalMinor;

  /// «Corte × 2» (la cantidad solo si es más de uno, como en la web).
  String get texto => cantidad > 1 ? '$descripcion × $cantidad' : descripcion;

  factory LineaPresupuesto.desdeJson(Map<String, dynamic> j) =>
      LineaPresupuesto(
        tipo: (j['tipo'] ?? '') as String,
        descripcion: (j['descripcion'] ?? '') as String,
        cantidad: (j['cantidad'] as num? ?? 1).toInt(),
        precioUnitarioMinor: (j['precio_unitario_minor'] as num? ?? 0).toInt(),
        descuentoMinor: (j['descuento_minor'] as num? ?? 0).toInt(),
        subtotalMinor: (j['subtotal_minor'] as num? ?? 0).toInt(),
      );
}

/// Cómo está un presupuesto para el cliente (punto + texto, como en la web).
enum SituacionPresupuesto {
  porResponder('Por responder'),
  aceptado('Aceptado'),
  pagado('Aceptado y pagado'),
  rechazado('Rechazado'),
  vencido('Vencido');

  const SituacionPresupuesto(this.texto);

  final String texto;
}
