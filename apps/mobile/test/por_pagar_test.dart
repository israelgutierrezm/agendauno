import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';

void main() {
  test('por pagar trae todo lo pendiente, también las citas', () {
    final ordenes = OrdenPorPagar.pendientes([
      {
        'id': 'renovacion',
        'estado': 'pendiente',
        'total_minor': 129900,
        'concepto': 'Mensualidad',
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
      // La de una cita: sin productos, con el servicio, con quién y cuándo.
      {
        'id': 'cita',
        'estado': 'pendiente',
        'total_minor': 50000,
        'concepto': 'Corte',
        'sesion': {
          'servicio': 'Corte',
          'profesional': 'Luis',
          'inicia_en': '2030-01-07T16:00:00Z',
          'sucursal': 'Centro',
        },
        'lineas': [],
      },
    ]);

    expect(ordenes.map((o) => o.id), ['renovacion', 'cita']);
    expect(ordenes.first.concepto, 'Mensualidad');
    expect(ordenes.first.detalle, isNull);
    expect(ordenes.last.concepto, 'Corte');
    expect(ordenes.last.detalle, startsWith('Con Luis · '));
    expect(ordenes.last.detalle, endsWith(' · Centro'));
  });

  test('sin concepto del servidor, junta los productos con su cantidad', () {
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

  test('la cobertura de una clase dice si se puede reservar y por qué no', () {
    final incluida = ClaseMiembro.desdeJson({
      'id': 's1',
      'cobertura': {'estado': 'incluida', 'motivo': null},
    });
    final membresia = ClaseMiembro.desdeJson({
      'id': 's2',
      'cobertura': {'estado': 'solo_membresia', 'motivo': 'clase'},
    });
    final sinSaldo = ClaseMiembro.desdeJson({
      'id': 's3',
      'cobertura': {'estado': 'no_incluida', 'motivo': 'saldo'},
    });
    final sinDato = ClaseMiembro.desdeJson({'id': 's4'});

    expect(incluida.reservable, isTrue);
    expect(incluida.cobertura!.texto, 'Incluida en tu plan');
    expect(membresia.reservable, isFalse);
    expect(membresia.cobertura!.texto, 'Solo con membresía');
    expect(
      membresia.cobertura!.motivoTexto,
      'Esta clase solo la incluye una membresía.',
    );
    expect(sinSaldo.cobertura!.texto, 'No incluida');
    expect(
      sinSaldo.cobertura!.motivoTexto,
      'Ya no te quedan clases en tu plan.',
    );
    // Un API anterior no lo dice: se intenta reservar.
    expect(sinDato.reservable, isTrue);
  });

  test('el plan trae su estado efectivo: en pausa no está vigente', () {
    final pausado = DerechoMiembro.desdeJson({
      'ilimitado': false,
      'disponible': 6000,
      'vence': '2030-01-31',
      'estado': 'pausado',
      'pausa_hasta': '2030-01-20',
    });
    final agotado = DerechoMiembro.desdeJson({
      'ilimitado': false,
      'disponible': 0,
      'estado': 'agotado',
    });

    expect(pausado.vigente, isFalse);
    expect(agotado.vigente, isTrue);
  });
}
