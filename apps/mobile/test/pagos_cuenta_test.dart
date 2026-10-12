import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_widgets.dart';
import 'package:agendauno/features/cuenta/presentation/historial_compras.dart';

/// Pagar en la app como en la web: con tarjeta o con una ficha de OXXO, dejando el
/// pago automático en lo que se renueva, sin pagar dos veces lo que ya está en
/// proceso, con la inscripción a la vista antes de comprar y con su historial de
/// pagos. Datos sintéticos.

/// Repositorio que anota los cobros y responde una ficha de OXXO.
class _RepoPagos extends CuentaRepository {
  _RepoPagos() : super(Dio(), 'demo');

  final cobros = <String>[];
  final paginas = <int>[];

  @override
  Future<CheckoutPago> pagarOrden(
    String ordenId, {
    String metodo = 'tarjeta',
    bool domiciliar = false,
  }) async {
    cobros.add('$ordenId:$metodo:$domiciliar');
    return metodo == 'oxxo'
        ? const FichaDePago(referencia: '9876 5432 1098')
        : const PagoPorConfirmar();
  }

  @override
  Future<PaginaCompras> historialCompras({int pagina = 1}) async {
    paginas.add(pagina);
    return PaginaCompras(
      pagina: pagina,
      ultimaPagina: 2,
      ordenes: [
        OrdenPorPagar.desdeJson({
          'id': 'h$pagina',
          'concepto': pagina == 1 ? 'Pack 8 clases' : 'Clase suelta',
          'estado': 'pagada',
          'total_minor': 89900,
          'moneda': 'MXN',
          'pagada_en': '2026-09-20T18:00:00Z',
        }),
      ],
    );
  }
}

class _CuentaQuieta extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);
}

const _ana = Sesion(slug: 'demo', bearer: 't', nombre: 'Ana', rol: 'miembro');

Future<_RepoPagos> _montar(WidgetTester tester, Widget hijo) async {
  final repo = _RepoPagos();
  tester.view.physicalSize = const Size(420, 900);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(_ana),
        cuentaRepositoryProvider.overrideWithValue(repo),
        cuentaProvider.overrideWith(_CuentaQuieta.new),
      ],
      child: MaterialApp(
        home: Scaffold(body: SingleChildScrollView(child: hijo)),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return repo;
}

void main() {
  test(
    'lo que debe dice si ya está en proceso, si se renueva y para quién',
    () {
      final o = OrdenPorPagar.desdeJson({
        'id': 'o1',
        'estado': 'pendiente',
        'total_minor': 89900,
        'recurrente': true,
        'pago_en_proceso': {'metodo': 'oxxo', 'iniciado_en': null},
        'lineas': [
          {'producto': 'Mensualidad', 'cantidad': 1, 'para': 'Sofía López'},
        ],
      });

      expect(o.recurrente, isTrue);
      expect(o.concepto, 'Mensualidad (para Sofía López)');
      expect(o.avisoEnProceso, contains('ficha para pagar en OXXO'));
      expect(
        OrdenPorPagar.desdeJson({
          'id': 'o2',
          'concepto': 'Corte',
          'total_minor': 100,
          'pago_en_proceso': null,
        }).avisoEnProceso,
        isNull,
      );
    },
  );

  test('el pago en línea sigue a la pasarela, a una ficha o en proceso', () {
    expect(
      CheckoutPago.desdeJson({'tipo': 'redirect', 'url': 'https://pagar'}),
      isA<IrAPagarEnLinea>(),
    );
    final ficha = CheckoutPago.desdeJson({
      'tipo': 'voucher',
      'referencia': '123',
      'codigo_barras': 'https://cb',
      'recibo': 'https://recibo',
    });
    expect(ficha, isA<FichaDePago>());
    expect((ficha as FichaDePago).referencia, '123');
    expect(CheckoutPago.desdeJson(null), isA<PagoPorConfirmar>());
  });

  test('la inscripción se dice antes de comprar', () {
    final p = ProductoComprable.desdeJson({
      'id': 'p1',
      'nombre': 'Mensual',
      'tipo': 'membresia',
      'precio_minor': 89900,
      'inscripcion_minor': 50000,
      'moneda': 'MXN',
    });
    expect(p.inscripcionTexto, r'Más $500.00 de inscripción.');
    expect(
      ProductoComprable.desdeJson({
        'id': 'p2',
        'nombre': 'Pack',
        'tipo': 'paquete',
        'precio_minor': 100,
      }).inscripcionTexto,
      isNull,
    );
  });

  testWidgets('pagar en OXXO muestra la ficha con su referencia', (
    tester,
  ) async {
    final repo = await _montar(
      tester,
      PorPagarTile(
        OrdenPorPagar.desdeJson({
          'id': 'o1',
          'concepto': 'Pack 8 clases',
          'total_minor': 89900,
        }),
        pagoEnLinea: true,
      ),
    );

    // Lo que no se renueva no ofrece el pago automático.
    expect(find.byType(Checkbox), findsNothing);
    await tester.tap(find.text('o paga en efectivo en OXXO'));
    await tester.pumpAndSettle();

    expect(repo.cobros, ['o1:oxxo:false']);
    expect(find.byKey(const Key('ficha-de-pago')), findsOneWidget);
    expect(find.text('Referencia: 9876 5432 1098'), findsOneWidget);
  });

  testWidgets('al pagar una renovación puede dejar el pago automático', (
    tester,
  ) async {
    final repo = await _montar(
      tester,
      PorPagarTile(
        OrdenPorPagar.desdeJson({
          'id': 'o2',
          'concepto': 'Mensualidad',
          'total_minor': 89900,
          'recurrente': true,
          'pago_en_proceso': {'metodo': 'tarjeta'},
        }),
        pagoEnLinea: true,
        pagoAutomatico: true,
      ),
    );

    expect(
      find.textContaining('Ya iniciaste un pago en línea'),
      findsOneWidget,
    );
    await tester.tap(find.byType(Checkbox));
    await tester.pump();
    await tester.tap(find.text('Pagar'));
    await tester.pumpAndSettle();

    expect(repo.cobros, ['o2:tarjeta:true']);
    expect(find.textContaining('Tu pago está en proceso'), findsOneWidget);
  });

  testWidgets('el historial de pagos se lee de 10 en 10', (tester) async {
    final repo = await _montar(tester, const HistorialComprasSeccion());

    expect(find.text('Pack 8 clases'), findsOneWidget);
    expect(find.text('Pagada'), findsOneWidget);
    await tester.tap(find.text('Ver más'));
    await tester.pumpAndSettle();

    expect(repo.paginas, [1, 2]);
    expect(find.text('Clase suelta'), findsOneWidget);
    // Era la última página.
    expect(find.text('Ver más'), findsNothing);
  });
}
