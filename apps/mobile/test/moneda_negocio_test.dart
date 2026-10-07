import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/formato.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/presentation/agenda_screen.dart';
import 'package:agendauno/features/agenda/presentation/cita_sheet.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/comprar_planes.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_widgets.dart';
import 'package:agendauno/features/inicio/data/resumen_hoy.dart';

/// La app muestra el dinero en la moneda del negocio (ADR 0099): con su código si
/// no es el peso mexicano, siempre con centavos y sin punto flotante. Lo que el API
/// manda sin moneda toma la de la sesión. Datos sintéticos.

/// Espacio duro entre el código y la cantidad.
const _nb = ' ';

/// Una cita de 12.50 por cobrar con Beto, como la manda GET /sesiones.
Map<String, dynamic> _citaPorCobrar() => {
  'id': 's1',
  'tipo': 'cita',
  'oferta': 'Corte',
  'oferta_id': 'o1',
  'oferta_precio_clase': 1250,
  'instructor': 'Beto Ramírez',
  'instructor_id': 'p1',
  'inicia_en': DateTime.now().toUtc().toIso8601String(),
  'termina_en': DateTime.now()
      .add(const Duration(hours: 1))
      .toUtc()
      .toIso8601String(),
  'capacidad': 1,
  'ocupados': 1,
  'en_espera': 0,
  'estado': 'programada',
  'cita': {
    'reserva_id': 'r1',
    'cliente': 'Ana López',
    'estado': 'confirmada',
    'orden_id': 'o9',
    'por_cobrar': true,
  },
};

class _AgendaConCita extends AgendaController {
  @override
  Future<AgendaDia> build() async => AgendaDia(
    sesiones: [SesionAgenda.desdeJson(_citaPorCobrar())],
    profesionales: const [Profesional(id: 'p1', nombre: 'Beto Ramírez')],
  );
}

/// Recepción de un negocio de citas que trabaja en euros.
const _recepcionEnEuros = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Recepción',
  rol: 'propietario',
  permisos: ['*'],
  modalidad: Modalidad.citas,
  moneda: 'EUR',
);

class _CuentaVacia extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);
}

/// /mi/productos y /mi/ordenes/pendientes sin moneda (respuesta antigua).
class _ApiSinMoneda implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final Object data = options.path.endsWith('/mi/productos')
        ? [
            {
              'id': 'p1',
              'nombre': 'Mensualidad',
              'tipo': 'membresia',
              'precio_minor': 15000000,
              'ilimitado': true,
            },
          ]
        : const <Object>[];
    return ResponseBody.fromString(
      jsonEncode({'data': data}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  group('Formato.dinero', () {
    test('en pesos mexicanos lleva «\$», como la web', () {
      expect(Formato.dinero(129900, 'MXN'), r'$1,299.00');
      expect(Formato.dinero(0, 'MXN'), r'$0.00');
    });

    test('en otra moneda lleva su código y conserva los centavos', () {
      expect(Formato.dinero(1250, 'EUR'), 'EUR${_nb}12.50');
      expect(Formato.dinero(2500000000, 'COP'), 'COP${_nb}25,000,000.00');
      expect(Formato.dinero(99, 'usd'), 'USD${_nb}0.99');
    });

    test('los negativos no pierden el signo ni los centavos', () {
      expect(Formato.dinero(-50, 'MXN'), r'-$0.50');
      expect(Formato.dinero(-123456, 'EUR'), '-EUR${_nb}1,234.56');
    });

    test('la moneda de la respuesta o, si no viene, la del negocio', () {
      expect(Formato.moneda('cop', 'MXN'), 'COP');
      expect(Formato.moneda(null, 'EUR'), 'EUR');
      expect(Formato.moneda('  ', 'EUR'), 'EUR');
    });
  });

  group('los modelos guardan la moneda', () {
    test('el plan, lo que debe y lo por cobrar del día', () {
      final plan = ProductoComprable.desdeJson({
        'id': 'p1',
        'nombre': 'Paquete',
        'tipo': 'paquete',
        'precio_minor': 89900,
        'moneda': 'EUR',
      });
      expect(plan.moneda, 'EUR');

      final orden = OrdenPorPagar.pendientes([
        {'id': 'o1', 'estado': 'pendiente', 'total_minor': 4500},
        {
          'id': 'o2',
          'estado': 'pendiente',
          'total_minor': 4500,
          'moneda': 'USD',
        },
      ], moneda: 'COP');
      expect(orden.map((o) => o.moneda), ['COP', 'USD']);

      final cobros = CobrosHoy.desdeJson({
        'ordenes_pendientes': 2,
        'por_cobrar': [
          {'moneda': 'COP', 'total_minor': 5000000},
          {'moneda': 'MXN', 'total_minor': 1000},
        ],
      }, moneda: 'COP');
      expect(cobros.moneda, 'COP');
      // Solo se suma lo de la moneda del negocio.
      expect(cobros.porCobrarMinor, 5000000);
    });

    test(
      'sin moneda en la respuesta, el repositorio pone la del negocio',
      () async {
        final repo = CuentaRepository(
          Dio()..httpClientAdapter = _ApiSinMoneda(),
          'demo',
          'COP',
        );
        final productos = await repo.productos();
        expect(productos.single.moneda, 'COP');
        expect(
          Formato.dinero(productos.single.precioMinor, productos.single.moneda),
          'COP${_nb}150,000.00',
        );
      },
    );
  });

  testWidgets('los planes se ofrecen en la moneda del negocio', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          cuentaProvider.overrideWith(_CuentaVacia.new),
          productosProvider.overrideWith(
            (ref) async => [
              ProductoComprable.desdeJson({
                'id': 'p1',
                'nombre': 'Paquete 8 clases',
                'tipo': 'paquete',
                'precio_minor': 89950,
                'moneda': 'EUR',
              }),
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

    expect(find.text('EUR${_nb}899.50'), findsOneWidget);
    expect(find.textContaining(r'$'), findsNothing);
  });

  testWidgets('lo que debe se muestra en su moneda', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        child: MaterialApp(
          home: Scaffold(
            body: PorPagarTile(
              OrdenPorPagar.desdeJson({
                'id': 'o1',
                'concepto': 'Mensualidad',
                'total_minor': 15000000,
                'moneda': 'COP',
              }),
            ),
          ),
        ),
      ),
    );

    expect(
      find.text('COP${_nb}150,000.00 · Págalo en recepción'),
      findsOneWidget,
    );
  });

  testWidgets('la agenda de citas suma lo por cobrar con centavos y en euros', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(600, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(_recepcionEnEuros),
          agendaProvider.overrideWith(_AgendaConCita.new),
        ],
        child: const MaterialApp(home: AgendaScreen()),
      ),
    );
    await tester.pump();
    await tester.pump();

    expect(find.text('EUR${_nb}12.50'), findsOneWidget);
    expect(find.text('por cobrar'), findsOneWidget);
    // Antes decía «$13»: otro signo y sin centavos.
    expect(find.text(r'$13'), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('la hoja de la cita cobra el precio exacto en su moneda', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(600, 1000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final cita = SesionAgenda.desdeJson(_citaPorCobrar());
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(_recepcionEnEuros),
          agendaProvider.overrideWith(_AgendaConCita.new),
        ],
        child: MaterialApp(
          home: Scaffold(
            body: Builder(
              builder: (context) => TextButton(
                onPressed: () => mostrarHojaCita(context, cita),
                child: const Text('Abrir'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Abrir'));
    await tester.pumpAndSettle();

    expect(find.text('Cobrar EUR${_nb}12.50'), findsOneWidget);
    expect(
      find.textContaining('Con Beto Ramírez · EUR${_nb}12.50'),
      findsOneWidget,
    );
  });
}
