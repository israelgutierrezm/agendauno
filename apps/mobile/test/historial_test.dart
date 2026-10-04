import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';

void main() {
  test('el historial dice qué pasó, si cambió de horario y su reseña', () {
    final pagina = PaginaHistorial.desdeJson({
      'data': [
        {
          'id': 'r1',
          'estado': 'asistio',
          'oferta': 'Pole Nivel 1',
          'reprogramada': true,
          'resena': {'calificacion': 5, 'comentario': 'Muy buena'},
          'calificable': false,
        },
        {
          'id': 'r2',
          'estado': 'cancelada',
          'cancelada_por': 'negocio',
          'resena': null,
        },
        {'id': 'r3', 'estado': 'cancelada', 'cancelada_por': 'cliente'},
      ],
      'meta': {'page': 1, 'ultima_pagina': 3, 'total': 40, 'per_page': 15},
    });

    expect(pagina.ultimaPagina, 3);
    final asistio = pagina.items[0];
    expect(asistio.estadoTexto, 'Asististe');
    expect(asistio.reprogramada, isTrue);
    expect(asistio.calificacion, 5);
    expect(asistio.comentario, 'Muy buena');
    expect(pagina.items[1].estadoTexto, 'Cancelada por el negocio');
    expect(pagina.items[1].calificacion, isNull);
    expect(pagina.items[2].estadoTexto, 'Cancelaste');
  });
}
