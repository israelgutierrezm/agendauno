import '../../../core/formato.dart';

/// Lo que compró en el mostrador del negocio (ADR 0145), como lo da
/// `GET /mi/compras-mostrador`: sus tickets, del más reciente al más antiguo. El
/// dinero llega en centavos (`*_minor`) con su moneda; nunca con punto flotante.
class CompraMostrador {
  const CompraMostrador({
    required this.id,
    required this.folio,
    required this.lineas,
    required this.totalMinor,
    required this.moneda,
    this.creadoEn,
    this.sucursal,
    this.propinaMinor = 0,
    this.metodoPago = '',
    this.anulada = false,
  });

  final String id;

  /// Folio corto del ticket (el final de su id), para ubicarlo en recepción.
  final String folio;

  /// Instante de la compra (ISO); se muestra en la hora del negocio.
  final String? creadoEn;
  final String? sucursal;
  final List<LineaCompraMostrador> lineas;

  /// Lo de los productos, sin la propina.
  final int totalMinor;
  final int propinaMinor;
  final String moneda;

  /// efectivo, tarjeta, transferencia… o `mixto` con varias formas (ADR 0177).
  final String metodoPago;

  /// Anulada en el negocio: se ve, pero tachada.
  final bool anulada;

  /// Lo que pagó en el mostrador: productos y propina (como en la web; el servicio
  /// de una cita ya está en su historial de pagos).
  int get pagadoMinor => totalMinor + propinaMinor;

  /// «2 × Agua, 1 × Licuado (proteína)».
  String get resumen => lineas.map((l) => l.texto).join(', ');

  /// Cuándo, dónde y el folio: «mar 20 oct · 18:05 · Roma Norte · Ticket 7K2M9QXA».
  String detalle({int? desfaseMin}) => [
    if (creadoEn != null) Formato.fechaHora(creadoEn, desfaseMin: desfaseMin),
    if (sucursal != null && sucursal!.isNotEmpty) sucursal!,
    'Ticket $folio',
  ].join(' · ');

  /// La forma de pago en palabras (como en la web).
  String get metodoTexto => switch (metodoPago) {
    'efectivo' => 'Efectivo',
    'transferencia' || 'spei' => 'Transferencia',
    'manual' => 'Tarjeta (terminal)',
    'tarjeta' => 'Tarjeta',
    'mixto' => 'Varias formas',
    _ => '',
  };

  /// [moneda]: la del negocio, si el ticket no trae la suya.
  factory CompraMostrador.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) => CompraMostrador(
    id: (j['id'] ?? '') as String,
    folio: (j['folio'] ?? '') as String,
    creadoEn: j['creado_en'] as String?,
    sucursal: j['sucursal'] as String?,
    lineas: ((j['lineas'] ?? const []) as List)
        .whereType<Map<String, dynamic>>()
        .map(LineaCompraMostrador.desdeJson)
        .toList(),
    totalMinor: (j['total_minor'] as num? ?? 0).toInt(),
    propinaMinor: (j['propina_minor'] as num? ?? 0).toInt(),
    moneda: Formato.moneda(j['moneda'], moneda),
    metodoPago: (j['metodo_pago'] ?? '') as String,
    anulada: j['anulada'] == true,
  );
}

/// Un producto del ticket: cuántos, cuál y sus extras.
class LineaCompraMostrador {
  const LineaCompraMostrador({
    required this.articulo,
    required this.cantidad,
    this.extras = const [],
  });

  final String? articulo;
  final int cantidad;
  final List<String> extras;

  String get texto {
    final base = '$cantidad × ${articulo ?? '—'}';
    return extras.isEmpty ? base : '$base (${extras.join(', ')})';
  }

  factory LineaCompraMostrador.desdeJson(Map<String, dynamic> j) =>
      LineaCompraMostrador(
        articulo: j['articulo'] as String?,
        cantidad: (j['cantidad'] as num? ?? 1).toInt(),
        extras: ((j['extras'] ?? const []) as List)
            .whereType<String>()
            .toList(),
      );
}

/// Una página de sus compras del mostrador.
class PaginaComprasMostrador {
  const PaginaComprasMostrador({
    required this.compras,
    required this.pagina,
    required this.ultimaPagina,
  });

  final List<CompraMostrador> compras;
  final int pagina;
  final int ultimaPagina;

  bool get hayMas => pagina < ultimaPagina;
}
