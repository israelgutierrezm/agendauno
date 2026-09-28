import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/presentation/comprar_planes.dart';

/// Comprar un plan desde la app: qué se ofrece, cómo se describe y que comprar crea
/// la orden (que luego se paga en "Por pagar").

class _CuentaFalsa extends CuentaController {
  final compras = <String>[];

  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);

  @override
  Future<void> comprar(String productoId) async => compras.add(productoId);
}

ProductoComprable producto(Map<String, dynamic> extra) =>
    ProductoComprable.desdeJson({
      'id': 'p1',
      'nombre': 'Paquete 8 clases',
      'tipo': 'paquete',
      'precio_minor': 89900,
      'moneda': 'MXN',
      'ilimitado': false,
      'creditos_incluidos': 8000,
      'vigencia_tipo': 'meses',
      'vigencia_cantidad': 1,
      ...extra,
    });

void main() {
  test('cada plan dice qué incluye y cuánto dura', () {
    final paquete = producto({});
    expect(paquete.tipoTexto, 'Paquete');
    expect(paquete.creditosTexto, '8 créditos');
    expect(paquete.vigenciaTexto, 'Vence 1 mes después de la compra');

    final mensual = producto({
      'tipo': 'membresia',
      'ilimitado': true,
      'vigencia_tipo': 'fin_de_mes',
      'vigencia_cantidad': 1,
    });
    expect(mensual.creditosTexto, 'Ilimitado');
    expect(mensual.vigenciaTexto, 'Vence al terminar el mes de compra');

    final suelta = producto({
      'tipo': 'sesion_individual',
      'creditos_incluidos': 1000,
      'vigencia_tipo': null,
    });
    expect(suelta.creditosTexto, '1 crédito');
    expect(suelta.vigenciaTexto, isNull);
  });

  test('las clases extra solo se ofrecen a quien tiene un paquete', () {
    final lista = [
      producto({}),
      producto({'id': 'x1', 'tipo': 'add_on', 'nombre': '2 clases extra'}),
    ];
    const paquete = DerechoMiembro(
      ilimitado: false,
      id: 'd1',
      disponible: 3000,
    );
    const ilimitado = DerechoMiembro(ilimitado: true, id: 'd2');

    expect(ProductoComprable.paraComprar(lista, const []).map((p) => p.id), [
      'p1',
    ]);
    expect(
      ProductoComprable.paraComprar(lista, const [ilimitado]),
      hasLength(1),
    );
    expect(ProductoComprable.paraComprar(lista, const [paquete]), hasLength(2));
    expect(lista[1].vigenciaTexto, 'Vencen con el paquete');
  });

  testWidgets('comprar crea la orden y avisa que falta pagarla', (
    tester,
  ) async {
    final cuenta = _CuentaFalsa();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          cuentaProvider.overrideWith(() => cuenta),
          productosProvider.overrideWith((ref) async => [producto({})]),
        ],
        child: const MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(child: ComprarPlanesSeccion()),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Comprar'), findsWidgets);
    expect(find.text('Paquete 8 clases'), findsOneWidget);
    expect(find.text('Vence 1 mes después de la compra'), findsOneWidget);

    await tester.tap(find.widgetWithText(FilledButton, 'Comprar'));
    await tester.pumpAndSettle();

    expect(cuenta.compras, ['p1']);
    expect(find.textContaining('Págala en "Por pagar"'), findsOneWidget);
  });

  testWidgets('sin planes no se muestra la sección', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [productosProvider.overrideWith((ref) async => [])],
        child: const MaterialApp(home: Scaffold(body: ComprarPlanesSeccion())),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Comprar'), findsNothing);
  });
}
