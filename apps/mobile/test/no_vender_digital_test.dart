import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_widgets.dart';

/// La app no vende contenido digital (decisión del dueño; reglas 3.1.1 y 3.1.3 de
/// Apple): una clase en línea de pago no se reserva ni se paga en la app, y una orden
/// digital no se cobra ahí. Lo ya comprado (una clase en línea que su plan incluye)
/// se reserva como siempre. Los textos son neutros: no dicen dónde comprar.

class _CuentaDigital extends CuentaController {
  _CuentaDigital([this.porPagar = const []]);

  final List<OrdenPorPagar> porPagar;

  @override
  Future<MiCuenta> build() async => MiCuenta(
    derechos: const [],
    reservas: const [],
    clases: const [],
    porPagar: porPagar,
  );
}

ClaseMiembro _claseDigital({required bool enLinea, required bool dePago}) {
  final manana = DateTime.now().add(const Duration(days: 1));
  return ClaseMiembro.desdeJson({
    'id': 's1',
    'tipo': 'clase',
    'oferta': 'Yoga en vivo',
    'inicia_en': DateTime(
      manana.year,
      manana.month,
      manana.day,
      10,
    ).toUtc().toIso8601String(),
    'clase': {
      'capacidad': 20,
      'ocupados': 3,
      'libres': 17,
      'de_pago': dePago,
      'precio_minor': dePago ? 15000 : null,
      'en_linea': enLinea,
    },
    'cobertura': {'estado': dePago ? 'de_pago' : 'incluida'},
  });
}

Future<void> _abrirClaseDigital(
  WidgetTester tester,
  ClaseMiembro clase, {
  AntesDeReservar? antes,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cuentaProvider.overrideWith(_CuentaDigital.new),
        antesDeReservarProvider.overrideWith((ref, id) async => antes),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => mostrarDetalleClase(context, clase),
              child: const Text('Abrir'),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('Abrir'));
  await tester.pumpAndSettle();
}

void main() {
  test('lee si la clase es en línea y si pagarla sería contenido digital', () {
    expect(_claseDigital(enLinea: true, dePago: true).pagoDigital, isTrue);
    expect(_claseDigital(enLinea: true, dePago: false).pagoDigital, isFalse);
    expect(_claseDigital(enLinea: false, dePago: true).pagoDigital, isFalse);
    expect(
      _claseDigital(enLinea: true, dePago: true).coberturaTexto,
      'Clase en línea',
    );
    // Un API que no lo manda: presencial.
    expect(
      ClaseMiembro.desdeJson({
        'id': 'x',
        'clase': {'de_pago': true},
      }).enLinea,
      isFalse,
    );

    final antes = AntesDeReservar.desdeJson({
      'resultado': 'reserva',
      'en_linea': true,
      'de_pago': {'precio_minor': 15000, 'moneda': 'MXN'},
    });
    expect(antes.pagoDigital, isTrue);
    expect(
      AntesDeReservar.desdeJson({
        'resultado': 'reserva',
        'en_linea': true,
      }).pagoDigital,
      isFalse,
    );
  });

  testWidgets('una clase en línea de pago no se reserva ni se paga en la app', (
    tester,
  ) async {
    await _abrirClaseDigital(
      tester,
      _claseDigital(enLinea: true, dePago: true),
      antes: AntesDeReservar.desdeJson({
        'resultado': 'reserva',
        'en_linea': true,
        'de_pago': {'precio_minor': 15000, 'moneda': 'MXN'},
      }),
    );

    expect(find.byKey(const Key('clase-en-linea-fuera')), findsOneWidget);
    expect(find.byKey(const Key('confirmar-reserva')), findsNothing);
    expect(find.text('Reservar y pagar'), findsNothing);
    expect(find.text('Reservar'), findsNothing);
    expect(find.text('Pago por clase'), findsNothing);
    // Neutro: no dice dónde comprarla.
    expect(find.textContaining('web'), findsNothing);
    expect(find.textContaining('Pagos'), findsNothing);
  });

  testWidgets('sin vista previa tampoco se ofrece reservarla', (tester) async {
    await _abrirClaseDigital(
      tester,
      _claseDigital(enLinea: true, dePago: true),
    );

    expect(find.byKey(const Key('clase-en-linea-fuera')), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Reservar'), findsNothing);
  });

  testWidgets('la clase en línea que su plan incluye se reserva como siempre', (
    tester,
  ) async {
    await _abrirClaseDigital(
      tester,
      _claseDigital(enLinea: true, dePago: false),
      antes: AntesDeReservar.desdeJson({
        'resultado': 'reserva',
        'en_linea': true,
        'plan': {'nombre': 'Mensual'},
        'unidades': 1000,
      }),
    );

    expect(find.byKey(const Key('clase-en-linea-fuera')), findsNothing);
    expect(find.byKey(const Key('confirmar-reserva')), findsOneWidget);
    expect(find.text('Confirmar reserva'), findsOneWidget);
  });

  testWidgets('una orden digital no se cobra en la app', (tester) async {
    final orden = OrdenPorPagar.desdeJson({
      'id': 'o1',
      'estado': 'pendiente',
      'total_minor': 15000,
      'concepto': 'Yoga en vivo',
      'digital': true,
      'lineas': <Object>[],
    });
    expect(orden.digital, isTrue);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          cuentaProvider.overrideWith(() => _CuentaDigital([orden])),
        ],
        child: MaterialApp(
          home: Scaffold(body: PorPagarTile(orden, pagoEnLinea: true)),
        ),
      ),
    );

    expect(find.widgetWithText(FilledButton, 'Pagar'), findsNothing);
    expect(find.text('o paga en efectivo en OXXO'), findsNothing);
    expect(
      find.textContaining('Este pago no se hace desde la app.'),
      findsOneWidget,
    );
  });

  testWidgets('una orden de lo presencial sí se paga en la app', (
    tester,
  ) async {
    final orden = OrdenPorPagar.desdeJson({
      'id': 'o2',
      'estado': 'pendiente',
      'total_minor': 89900,
      'concepto': 'Pack 8 clases',
      'lineas': <Object>[],
    });
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          cuentaProvider.overrideWith(() => _CuentaDigital([orden])),
        ],
        child: MaterialApp(
          home: Scaffold(body: PorPagarTile(orden, pagoEnLinea: true)),
        ),
      ),
    );

    expect(find.widgetWithText(FilledButton, 'Pagar'), findsOneWidget);
  });
}
