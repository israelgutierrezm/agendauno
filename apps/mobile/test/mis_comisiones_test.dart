import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/instructor/application/mis_clases_controller.dart';
import 'package:agendauno/features/instructor/data/comisiones_repository.dart';
import 'package:agendauno/features/instructor/data/mis_comisiones.dart';
import 'package:agendauno/features/instructor/presentation/instructor_screen.dart';
import 'package:agendauno/features/instructor/presentation/mis_comisiones_screen.dart';

/// «Mis comisiones» de quien atiende citas (ADR 0219): lo ganado (comisión y
/// propinas), las propinas y lo por venir del periodo, en la moneda del negocio; el
/// acceso solo en citas y con la función del plan. Datos sintéticos.

/// Como responde `GET /mis-comisiones`: su esquema (40 %) y sus propinas.
const _filas = [
  {
    'usuario_id': '01JPROF',
    'usuario': 'Ana',
    'tipo': 'porcentaje_venta',
    'monto_minor': 0,
    'porcentaje_bp': 4000,
    'moneda': 'MXN',
    'vigente_desde': '2026-01-01',
    'unidades': 3.0,
    'monto_total_minor': 36000,
    'unidades_por_venir': 2.0,
    'por_venir_minor': 20000,
    'al_cierre_minor': 56000,
  },
  {
    'usuario_id': '01JPROF',
    'usuario': 'Ana',
    'tipo': 'propinas',
    'monto_minor': 0,
    'porcentaje_bp': null,
    'moneda': 'MXN',
    'vigente_desde': null,
    'unidades': 2.0,
    'monto_total_minor': 15000,
    'unidades_por_venir': 0.0,
    'por_venir_minor': 0,
    'al_cierre_minor': 15000,
  },
];

/// Responde [cuerpo] con [estado] en `/mis-comisiones` y guarda lo que se pidió.
class _Api implements HttpClientAdapter {
  _Api({this.cuerpo = const {'data': _filas}, this.estado = 200});

  final Object cuerpo;
  final int estado;
  final List<RequestOptions> pedidos = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final mias = options.path.endsWith('/mis-comisiones');
    if (mias) {
      pedidos.add(options);
    }
    return ResponseBody.fromString(
      jsonEncode(mias ? cuerpo : {'message': 'No'}),
      mias ? estado : 404,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

const _profesional = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Ana',
  rol: 'instructor',
  modalidad: Modalidad.citas,
);

Future<void> _montar(WidgetTester tester, Widget pantalla, _Api api) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(_profesional),
        comisionesRepositoryProvider.overrideWithValue(
          ComisionesRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
      ],
      child: MaterialApp(home: pantalla),
    ),
  );
  await tester.pumpAndSettle();
}

/// El Inicio de quien atiende, sin red (sus citas, vacías).
Future<void> _montarInicio(WidgetTester tester, Sesion sesion) async {
  tester.view.physicalSize = const Size(390, 1400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(sesion),
        proximasMisClasesProvider.overrideWith(
          (ref) async => const <SesionAgenda>[],
        ),
        misClasesProvider.overrideWith((ref) async => const <SesionAgenda>[]),
        climaEquipoProvider.overrideWith((ref) async => null),
        comisionesRepositoryProvider.overrideWithValue(
          ComisionesRepository(Dio()..httpClientAdapter = _Api(), 'demo'),
        ),
      ],
      child: const MaterialApp(home: InstructorScreen()),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  group('lo que dice el API', () {
    test('suma lo ganado, las propinas y lo por venir', () {
      final c = MisComisiones.desdeJson(_filas);

      expect(c.comisionMinor, 36000);
      expect(c.propinasMinor, 15000);
      expect(c.ganadoMinor, 51000);
      expect(c.porVenirMinor, 20000);
      expect(c.atendidas, 3);
      expect(c.esquema, '40 % de lo cobrado');
      expect(c.moneda, 'MXN');
    });

    test('sin filas, la moneda del negocio y nada que mostrar', () {
      final c = MisComisiones.desdeJson(const [], moneda: 'COP');

      expect(c.vacio, isTrue);
      expect(c.moneda, 'COP');
      expect(c.esquema, isNull);
      expect(MisComisiones.desdeJson(null).vacio, isTrue);
    });

    test('un esquema fijo o por hora no se dice como porcentaje', () {
      final porHora = MisComisiones.desdeJson([
        {
          'tipo': 'por_hora',
          'monto_minor': 20000,
          'moneda': 'MXN',
          'unidades': 1.5,
          'monto_total_minor': 30000,
          'por_venir_minor': 0,
        },
      ]);

      expect(porHora.esquema, 'Tu esquema de pago');
      // Cuenta horas, no citas: no se dice cuántas atendió.
      expect(porHora.cuentaAtendidas, isFalse);
      // Solo propinas: sin esquema.
      expect(MisComisiones.desdeJson([_filas[1]]).esquema, isNull);
    });

    test('el porcentaje sale de los puntos base sin redondear', () {
      expect(MisComisiones.porcentaje(4000), '40');
      expect(MisComisiones.porcentaje(1250), '12.5');
      expect(MisComisiones.porcentaje(1234), '12.34');
      expect(MisComisiones.porcentaje(5), '0.05');
    });

    test('el periodo se dice corto, con el año si no es el de hoy', () {
      final hoy = DateTime(2026, 10, 26);
      expect(
        textoPeriodo(DateTime(2026, 10, 1), DateTime(2026, 10, 26), hoy: hoy),
        '1 oct – 26 oct',
      );
      expect(
        textoPeriodo(DateTime(2026, 10, 5), DateTime(2026, 10, 5), hoy: hoy),
        '5 oct',
      );
      expect(
        textoPeriodo(DateTime(2025, 12, 1), DateTime(2026, 1, 15), hoy: hoy),
        '1 dic 2025 – 15 ene 2026',
      );
    });
  });

  test('pide lo suyo del periodo, del día 1 a hoy', () async {
    final api = _Api();
    final repo = ComisionesRepository(Dio()..httpClientAdapter = api, 'demo');

    final c = await repo.delPeriodo(
      DateTime(2026, 10, 1),
      DateTime(2026, 10, 26),
    );

    expect(c.ganadoMinor, 51000);
    final pedido = api.pedidos.single;
    expect(pedido.path, '/api/v1/app/demo/mis-comisiones');
    expect(pedido.queryParameters, {
      'desde': '2026-10-01',
      'hasta': '2026-10-26',
    });
  });

  testWidgets('muestra lo ganado, las propinas y lo por venir', (tester) async {
    final api = _Api();
    await _montar(tester, const MisComisionesScreen(), api);

    expect(find.text('Mis comisiones'), findsOneWidget);
    expect(find.text(r'$510.00'), findsOneWidget);
    expect(find.text(r'$150.00'), findsOneWidget);
    expect(find.text(r'$200.00'), findsOneWidget);
    expect(find.text('40 % de lo cobrado · Atendiste 3 citas'), findsOneWidget);
    // Del día 1 del mes a hoy.
    final pedido = api.pedidos.single.queryParameters;
    expect((pedido['desde'] as String).endsWith('-01'), isTrue);
  });

  testWidgets('en otra moneda, con su código', (tester) async {
    final api = _Api(
      cuerpo: {
        'data': [
          for (final f in _filas) {...f, 'moneda': 'COP'},
        ],
      },
    );
    await _montar(tester, const MisComisionesScreen(), api);

    expect(find.text('COP 510.00'), findsOneWidget);
    expect(find.text(r'$510.00'), findsNothing);
  });

  testWidgets('sin nada en el periodo lo dice', (tester) async {
    await _montar(
      tester,
      const MisComisionesScreen(),
      _Api(cuerpo: const {'data': []}),
    );

    expect(
      find.text('Aún no tienes comisiones en este periodo.'),
      findsOneWidget,
    );
  });

  testWidgets('si el plan no lo incluye, lo dice y deja reintentar', (
    tester,
  ) async {
    await _montar(
      tester,
      const MisComisionesScreen(),
      _Api(
        estado: 403,
        cuerpo: const {
          'message': 'Tu plan no incluye esta función.',
          'code': 'PLAN_FEATURE_NOT_INCLUDED',
        },
      ),
    );

    expect(
      find.text('Esta opción no está disponible en este negocio.'),
      findsOneWidget,
    );
    expect(find.text('Reintentar'), findsOneWidget);
    // Un error nunca se dice como «no hay nada».
    expect(
      find.text('Aún no tienes comisiones en este periodo.'),
      findsNothing,
    );
  });

  group('el acceso en su Inicio', () {
    testWidgets('en citas con la función del plan, abre Mis comisiones', (
      tester,
    ) async {
      await _montarInicio(tester, _profesional);

      final acceso = find.byKey(const Key('acceso-mis-comisiones'));
      expect(acceso, findsOneWidget);

      await tester.ensureVisible(acceso);
      await tester.tap(acceso);
      await tester.pumpAndSettle();

      expect(find.byType(MisComisionesScreen), findsOneWidget);
      expect(find.text(r'$510.00'), findsOneWidget);
    });

    testWidgets('sin la función en su plan, no aparece', (tester) async {
      await _montarInicio(
        tester,
        const Sesion(
          slug: 'demo',
          bearer: 't',
          nombre: 'Ana',
          rol: 'instructor',
          modalidad: Modalidad.citas,
          nivelPlan: 'individual',
          funcionesSin: ['comisiones'],
        ),
      );

      expect(find.byKey(const Key('acceso-mis-comisiones')), findsNothing);
    });

    testWidgets('en un negocio de clases, no aparece', (tester) async {
      await _montarInicio(
        tester,
        const Sesion(
          slug: 'demo',
          bearer: 't',
          nombre: 'Mariana',
          rol: 'instructor',
          modalidad: Modalidad.clases,
        ),
      );

      expect(find.byKey(const Key('acceso-mis-comisiones')), findsNothing);
    });
  });
}
