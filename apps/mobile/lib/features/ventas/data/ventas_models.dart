import '../../../core/formato.dart';

/// Vender y cobrar desde la app del equipo, con las mismas rutas que la web
/// (`/ordenes`): cobrar en caja una orden pendiente y la venta rápida de un plan.
/// El dinero siempre en centavos (`*_minor`) con su moneda: nunca double.

/// Las formas de cobrar en caja, en el orden de la web. «Tarjeta» es la terminal del
/// negocio (no la pasarela en línea).
abstract final class FormasCobro {
  static const efectivo = 'efectivo';
  static const tarjeta = 'tarjeta';
  static const transferencia = 'transferencia';
  static const ventanilla = 'ventanilla';
  static const todas = [efectivo, tarjeta, transferencia, ventanilla];

  static String nombre(String forma) => switch (forma) {
    efectivo => 'Efectivo',
    tarjeta => 'Tarjeta (terminal)',
    transferencia => 'Transferencia',
    ventanilla => 'Depósito en ventanilla',
    _ => forma,
  };

  /// Las de contado: con ellas aplica el descuento del negocio (ADR 0191).
  static const contado = [efectivo, transferencia];
}

/// Lo que se cobraría de contado (todo en efectivo o por transferencia), como lo
/// calcula el servidor (ADR 0191): el total menos el porcentaje del negocio (tope
/// 30 %), una sola vez. Null si no aplica (otra forma, 0 % o ya descontado).
int? totalDeContado({
  required int totalMinor,
  required int porcentaje,
  required String forma,
  int yaDescontadoMinor = 0,
}) {
  if (!FormasCobro.contado.contains(forma) ||
      porcentaje <= 0 ||
      yaDescontadoMinor > 0 ||
      totalMinor <= 0) {
    return null;
  }
  final descuento = totalMinor * (porcentaje > 30 ? 30 : porcentaje) ~/ 100;
  return descuento > 0 ? totalMinor - descuento : null;
}

/// Una orden de `GET /ordenes` (la lista del equipo): quién compra, qué y cuánto.
class OrdenEquipo {
  const OrdenEquipo({
    required this.id,
    required this.estado,
    required this.totalMinor,
    required this.moneda,
    this.comprador,
    this.concepto = '—',
    this.descuentoContadoMinor = 0,
  });

  final String id;

  /// Lo que ya se le descontó por pagar de contado (no se descuenta dos veces).
  final int descuentoContadoMinor;

  /// `pendiente`, `pagada`, `cancelada`…
  final String estado;
  final int totalMinor;
  final String moneda;
  final String? comprador;

  /// Lo que lleva, en una línea («Pack 8 clases, Inscripción»).
  final String concepto;

  bool get pendiente => estado == 'pendiente';

  String get total => Formato.dinero(totalMinor, moneda);

  factory OrdenEquipo.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) {
    final lineas = ((j['lineas'] ?? const []) as List)
        .whereType<Map<String, dynamic>>()
        .map((l) {
          final nombre = (l['producto'] as String?)?.trim();
          final cantidad = (l['cantidad'] as num? ?? 1).toInt();
          final para = (l['beneficiario'] as String?)?.trim();
          if (nombre == null || nombre.isEmpty) {
            return null;
          }
          return [
            cantidad > 1 ? '$nombre × $cantidad' : nombre,
            if (para != null && para.isNotEmpty) '(para $para)',
          ].join(' ');
        })
        .whereType<String>()
        .toList();
    return OrdenEquipo(
      id: (j['id'] ?? '') as String,
      estado: (j['estado'] ?? 'pendiente') as String,
      totalMinor: (j['total_minor'] as num? ?? 0).toInt(),
      moneda: Formato.moneda(j['moneda'], moneda),
      comprador: (j['comprador'] as String?)?.trim(),
      concepto: lineas.isEmpty ? '—' : lineas.join(', '),
      descuentoContadoMinor: (j['descuento_contado_minor'] as num? ?? 0)
          .toInt(),
    );
  }
}

/// Una página de órdenes y si hay más.
class PaginaOrdenes {
  const PaginaOrdenes({
    required this.ordenes,
    required this.pagina,
    required this.ultimaPagina,
    this.total = 0,
  });

  final List<OrdenEquipo> ordenes;
  final int pagina;
  final int ultimaPagina;
  final int total;

  bool get hayMas => pagina < ultimaPagina;
}

/// Un plan a la venta (`GET /productos`, sin los archivados): membresía, paquete,
/// bono, clase suelta… con lo que hay que decir antes de cobrarlo.
class PlanVenta {
  const PlanVenta({
    required this.id,
    required this.nombre,
    required this.tipo,
    required this.precioMinor,
    required this.moneda,
    this.archivado = false,
    this.cobraInscripcion = false,
    this.permanenciaMeses = 0,
    this.condiciones,
  });

  final String id;
  final String nombre;

  /// `membresia`, `paquete`, `sesion_individual`, `pase_dia`, `add_on`, `taller`.
  final String tipo;
  final int precioMinor;
  final String moneda;
  final bool archivado;

  /// Suma la inscripción si a esa persona le toca (B04): se pregunta al API.
  final bool cobraInscripcion;

  /// Con permanencia, el cliente acepta sus condiciones antes de pagar.
  final int permanenciaMeses;
  final String? condiciones;

  bool get pideCondiciones => permanenciaMeses > 0;

  String get precio => Formato.dinero(precioMinor, moneda);

  /// En un negocio de citas, la membresía y el paquete son bonos (ADR 0130).
  String tipoTexto({required bool esCitas}) => switch (tipo) {
    'membresia' => esCitas ? 'Bono' : 'Membresía',
    'paquete' => esCitas ? 'Bono' : 'Paquete',
    'sesion_individual' => esCitas ? 'Cita suelta' : 'Clase suelta',
    'pase_dia' => 'Pase de día',
    'add_on' => 'Extra',
    'taller' => 'Taller',
    _ => tipo,
  };

  factory PlanVenta.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) => PlanVenta(
    id: (j['id'] ?? '') as String,
    nombre: ((j['nombre'] ?? '') as String).trim(),
    tipo: (j['tipo'] ?? '') as String,
    precioMinor: (j['precio_minor'] as num? ?? 0).toInt(),
    moneda: Formato.moneda(j['moneda'], moneda),
    archivado: j['archivado'] == true,
    cobraInscripcion: j['cobra_inscripcion'] == true,
    permanenciaMeses: (j['permanencia_meses'] as num? ?? 0).toInt(),
    condiciones: switch (j['condiciones']) {
      final String c when c.trim().isNotEmpty => c.trim(),
      _ => null,
    },
  );
}
