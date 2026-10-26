import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:flutter_test/flutter_test.dart';

// Quién da cada servicio (ADR 0217): al agendar solo se ofrece a quienes lo dan; sin
// nadie elegido, lo da cualquiera.
void main() {
  final opciones = OpcionesCita(
    servicios: [
      OpcionCita.desdeJson({
        'id': 'color',
        'nombre': 'Color',
        'profesionales': ['carla'],
      }),
      OpcionCita.desdeJson({'id': 'corte', 'nombre': 'Corte'}),
    ],
    sucursales: const [],
    profesionales: [
      OpcionCita.desdeJson({
        'id': 'beto',
        'nombre': 'Beto',
        'sucursales': ['roma'],
      }),
      OpcionCita.desdeJson({
        'id': 'carla',
        'nombre': 'Carla',
        'sucursales': ['roma'],
      }),
    ],
  );

  test('con un servicio, solo quienes lo dan', () {
    final color = opciones.servicios.first;
    expect(
      opciones.profesionalesEn('roma', servicio: color).map((p) => p.id),
      ['carla'],
    );
  });

  test('sin nadie elegido, lo da todo el equipo de la sede', () {
    final corte = opciones.servicios.last;
    expect(
      opciones.profesionalesEn('roma', servicio: corte).map((p) => p.id),
      ['beto', 'carla'],
    );
    expect(opciones.profesionalesEn('roma').length, 2);
  });
}
