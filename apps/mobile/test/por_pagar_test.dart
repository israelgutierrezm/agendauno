import 'package:flutter_test/flutter_test.dart';
import 'package:turnouno_mobile/features/cuenta/data/cuenta_models.dart';

void main() {
  test('por pagar solo trae lo pendiente con productos', () {
    final ordenes = OrdenPorPagar.pendientes([
      {
        'id': 'renovacion',
        'estado': 'pendiente',
        'total_minor': 129900,
        'lineas': [
          {'producto': 'Mensualidad', 'cantidad': 1},
        ],
      },
      {
        'id': 'pagada',
        'estado': 'pagada',
        'total_minor': 89900,
        'lineas': [
          {'producto': 'Pack 8 clases', 'cantidad': 1},
        ],
      },
      // La de una cita apartada se paga desde la reserva.
      {'id': 'cita', 'estado': 'pendiente', 'total_minor': 50000, 'lineas': []},
    ]);

    expect(ordenes, hasLength(1));
    expect(ordenes.single.id, 'renovacion');
    expect(ordenes.single.concepto, 'Mensualidad');
    expect(ordenes.single.totalMinor, 129900);
  });

  test('el concepto junta los productos con su cantidad', () {
    final orden = OrdenPorPagar.desdeJson({
      'id': 'o1',
      'estado': 'pendiente',
      'total_minor': 1000,
      'lineas': [
        {'producto': 'Agua', 'cantidad': 2},
        {'producto': 'Toalla', 'cantidad': 1},
      ],
    });

    expect(orden.concepto, 'Agua × 2, Toalla');
  });
}
