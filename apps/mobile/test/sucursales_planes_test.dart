import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/corte_planes.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/presentation/comprar_planes.dart';

/// En qué sucursales vale un plan (`todas_sucursales` y `sucursales` de
/// /mi/productos, /mi/perfil y /mi/planes): si no vale en todas, se dice en
/// cuáles. Datos sintéticos.

const _soloDos = {
  'todas_sucursales': false,
  'sucursales': [
    {'id': 's1', 'nombre': 'Condesa'},
    {'id': 's2', 'nombre': 'Roma Norte'},
  ],
};

void main() {
  test('si no vale en todas, dice en cuáles; si vale en todas, nada', () {
    expect(
      CoberturaSucursales.desdeJson(_soloDos).texto,
      'Solo en Condesa, Roma Norte',
    );
    expect(
      CoberturaSucursales.desdeJson({
        'todas_sucursales': true,
        'sucursales': <Object>[],
      }).texto,
      isNull,
    );
    // Un API anterior no lo manda: vale en todas.
    expect(CoberturaSucursales.desdeJson(const {}).texto, isNull);
  });

  test('lo traen los planes que compra, los que tiene y su corte', () {
    final producto = ProductoComprable.desdeJson({
      'id': 'p1',
      'nombre': 'Paquete 8 clases',
      'tipo': 'paquete',
      'precio_minor': 89900,
      'ilimitado': false,
      ..._soloDos,
    });
    final derecho = DerechoMiembro.desdeJson({
      'id': 'd1',
      'ilimitado': true,
      ..._soloDos,
    });
    final corte = PlanCorte.desdeJson({
      'id': 'a1',
      'derecho_id': 'd1',
      'producto': 'Paquete 8 clases',
      'estado': 'vigente',
      'ilimitado': false,
      'unidades': {'incluidas': 8000},
      ..._soloDos,
    });

    expect(producto.cobertura.texto, 'Solo en Condesa, Roma Norte');
    expect(derecho.cobertura.sucursales, ['Condesa', 'Roma Norte']);
    expect(corte.cobertura.texto, 'Solo en Condesa, Roma Norte');
  });

  testWidgets('al comprar, el plan dice en qué sucursales vale', (
    tester,
  ) async {
    ProductoComprable plan(String id, Map<String, dynamic> extra) =>
        ProductoComprable.desdeJson({
          'id': id,
          'nombre': 'Plan $id',
          'tipo': 'paquete',
          'precio_minor': 50000,
          'ilimitado': false,
          ...extra,
        });
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          productosProvider.overrideWith(
            (ref) async => [
              plan('a', _soloDos),
              plan('b', {'todas_sucursales': true}),
            ],
          ),
        ],
        child: const MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(child: ComprarPlanesSeccion()),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Solo en Condesa, Roma Norte'), findsOneWidget);
    expect(find.textContaining('Todas'), findsNothing);
  });
}
