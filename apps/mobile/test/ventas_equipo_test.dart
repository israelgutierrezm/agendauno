import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/inicio/data/inicio_repository.dart';
import 'package:agendauno/features/inicio/data/resumen_hoy.dart';
import 'package:agendauno/features/inicio/presentation/equipo_screen.dart';
import 'package:agendauno/features/ventas/data/ventas_models.dart';
import 'package:agendauno/features/ventas/data/ventas_repository.dart';
import 'package:agendauno/features/ventas/presentation/por_cobrar_screen.dart';
import 'package:agendauno/features/ventas/presentation/vender_screen.dart';

/// Cobrar y vender desde la app del equipo, como en la web: lo pendiente se cobra en
/// caja (`/liquidar`, con el precio de contado del negocio), y la venta rápida de un
/// plan se vende y se cobra de una vez, con su clave de intento. Cada cosa con su
/// permiso y en la caja de una sede. Datos sintéticos, sin red real.

class _ApiVentas implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  /// La primera petición se pierde (sin red) si se pide.
  bool perderLaPrimera = false;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    if (perderLaPrimera && pedidos.length == 1) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'sin red',
      );
    }
    final Object cuerpo = switch (options.path) {
      final p when p.endsWith('/ordenes') && options.method == 'GET' => {
        'data': [
          {
            'id': 'o1',
            'comprador': 'Ana López',
            'estado': 'pendiente',
            'total_minor': 100000,
            'moneda': 'MXN',
            'lineas': [
              {'producto': 'Mensual', 'cantidad': 1},
              {'producto': 'Inscripción', 'cantidad': 1},
            ],
          },
        ],
        'meta': {'page': 1, 'ultima_pagina': 2, 'total': 21},
      },
      final p when p.endsWith('/productos') => {
        'data': [
          {
            'id': 'p1',
            'nombre': 'Mensual',
            'tipo': 'membresia',
            'precio_minor': 89900,
            'moneda': 'MXN',
            'cobra_inscripcion': true,
            'permanencia_meses': 0,
          },
          {
            'id': 'p2',
            'nombre': 'Viejo',
            'tipo': 'paquete',
            'precio_minor': 100,
            'archivado': true,
          },
        ],
      },
      final p when p.endsWith('/cuotas') => {
        'data': {'inscripcion_minor': 50000},
      },
      _ => {
        'data': {
          'id': 'o1',
          'estado': 'pagada',
          'total_minor': 90000,
          'moneda': 'MXN',
          'lineas': <Object>[],
        },
      },
    };
    return ResponseBody.fromString(
      jsonEncode(cuerpo),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

class _AgendaSedes extends AgendaRepository {
  _AgendaSedes(this.sedes) : super(Dio(), 'demo');

  final List<SucursalAgenda> sedes;

  @override
  Future<List<SucursalAgenda>> sucursales() async => sedes;

  @override
  Future<List<ClienteAgenda>> buscarClientes(String texto) async => [
    const ClienteAgenda(id: 'per1', nombre: 'Ana López'),
  ];
}

class _VentasFalsas extends VentasRepository {
  _VentasFalsas() : super(Dio(), 'demo');

  final hechas = <String>[];

  @override
  Future<PaginaOrdenes> pendientes({int pagina = 1}) async => PaginaOrdenes(
    ordenes: [
      OrdenEquipo.desdeJson({
        'id': 'o1',
        'comprador': 'Ana López',
        'estado': 'pendiente',
        'total_minor': 100000,
        'moneda': 'MXN',
        'lineas': [
          {'producto': 'Mensual', 'cantidad': 1},
        ],
      }),
    ],
    pagina: 1,
    ultimaPagina: 1,
  );

  @override
  Future<OrdenEquipo> cobrar(
    String ordenId, {
    required String forma,
    String? sucursalId,
    String? referencia,
  }) async {
    hechas.add('cobrar:$ordenId:$forma:$sucursalId');
    return OrdenEquipo.desdeJson({
      'id': ordenId,
      'estado': 'pagada',
      'total_minor': 90000,
      'moneda': 'MXN',
    });
  }

  @override
  Future<List<PlanVenta>> planes() async => [
    PlanVenta.desdeJson({
      'id': 'p1',
      'nombre': 'Anual',
      'tipo': 'membresia',
      'precio_minor': 899000,
      'moneda': 'MXN',
      'permanencia_meses': 12,
      'condiciones': 'Si cancelas antes, pagas el 20 %.',
    }),
  ];

  @override
  Future<OrdenEquipo> vender({
    required String personaId,
    required String planId,
    required String forma,
    String? sucursalId,
    bool aceptaCondiciones = false,
  }) async {
    hechas.add(
      'vender:$personaId:$planId:$forma:$sucursalId:$aceptaCondiciones',
    );
    return OrdenEquipo.desdeJson({'id': 'o2', 'estado': 'pagada'});
  }
}

Sesion _sesionVentas({int contado = 0, List<String> permisos = const ['*']}) =>
    Sesion(
      slug: 'demo',
      bearer: 't',
      nombre: 'Recepción',
      rol: 'recepcionista',
      permisos: permisos,
      modalidad: Modalidad.clases,
      descuentoContadoPct: contado,
    );

Future<void> _montar(
  WidgetTester tester,
  Widget pantalla, {
  required _VentasFalsas ventas,
  List<SucursalAgenda> sedes = const [
    SucursalAgenda(id: 'su1', nombre: 'Centro'),
  ],
  int contado = 0,
}) async {
  tester.view.physicalSize = const Size(420, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(
          _sesionVentas(contado: contado),
        ),
        ventasRepositoryProvider.overrideWithValue(ventas),
        agendaRepositoryProvider.overrideWithValue(_AgendaSedes(sedes)),
      ],
      child: MaterialApp(home: pantalla),
    ),
  );
  await tester.pumpAndSettle();
}

class _AgendaSinNada extends AgendaController {
  @override
  Future<AgendaDia> build() async =>
      const AgendaDia(sesiones: [], profesionales: []);
}

Future<void> _montarInicio(WidgetTester tester, List<String> permisos) async {
  tester.view.physicalSize = const Size(390, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      key: UniqueKey(),
      overrides: [
        sesionInicialProvider.overrideWithValue(
          _sesionVentas(permisos: permisos),
        ),
        resumenHoyProvider.overrideWith(
          (ref) async => ResumenHoy.desdeJson({
            'fecha': '2026-10-25',
            'agenda': null,
            'cobros': {
              'ordenes_pendientes': 2,
              'por_cobrar': [
                {'moneda': 'MXN', 'total_minor': 50000},
              ],
              'en_mora': 0,
            },
          }),
        ),
        climaNegocioProvider.overrideWith((ref) async => null),
        agendaProvider.overrideWith(_AgendaSinNada.new),
        ventasRepositoryProvider.overrideWithValue(_VentasFalsas()),
        agendaRepositoryProvider.overrideWithValue(
          _AgendaSedes(const [SucursalAgenda(id: 'su1', nombre: 'Centro')]),
        ),
      ],
      child: const MaterialApp(home: EquipoScreen()),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('el Inicio del equipo abre «Por cobrar» y «Vender» con permiso', (
    tester,
  ) async {
    await _montarInicio(tester, ['*']);
    expect(find.byKey(const Key('vender')), findsOneWidget);
    await tester.tap(find.byKey(const Key('abrir-por-cobrar')));
    await tester.pumpAndSettle();
    expect(find.byType(PorCobrarScreen), findsOneWidget);

    // Sin permiso de cobrar ni de vender, solo se ve lo pendiente.
    await _montarInicio(tester, ['agenda.ver']);
    expect(find.textContaining('órdenes por cobrar'), findsOneWidget);
    expect(find.byKey(const Key('abrir-por-cobrar')), findsNothing);
    expect(find.byKey(const Key('vender')), findsNothing);
  });

  test('el precio de contado se calcula como el servidor', () {
    expect(
      totalDeContado(totalMinor: 100000, porcentaje: 10, forma: 'efectivo'),
      90000,
    );
    expect(
      totalDeContado(
        totalMinor: 100000,
        porcentaje: 10,
        forma: 'transferencia',
      ),
      90000,
    );
    // Con tarjeta, sin descuento; tope de 30 %; una sola vez.
    expect(
      totalDeContado(totalMinor: 100000, porcentaje: 10, forma: 'tarjeta'),
      isNull,
    );
    expect(
      totalDeContado(totalMinor: 100000, porcentaje: 50, forma: 'efectivo'),
      70000,
    );
    expect(
      totalDeContado(
        totalMinor: 90000,
        porcentaje: 10,
        forma: 'efectivo',
        yaDescontadoMinor: 10000,
      ),
      isNull,
    );
    expect(
      totalDeContado(totalMinor: 999, porcentaje: 10, forma: 'efectivo'),
      900,
    );
  });

  test(
    'las rutas: pendientes, cobrar en caja, planes, inscripción y vender',
    () async {
      final api = _ApiVentas();
      final repo = VentasRepository(Dio()..httpClientAdapter = api, 'demo');

      final pagina = await repo.pendientes();
      expect(pagina.ordenes.single.concepto, 'Mensual, Inscripción');
      expect(pagina.hayMas, isTrue);
      final cobrada = await repo.cobrar(
        'o1',
        forma: 'efectivo',
        sucursalId: 'su1',
      );
      expect(cobrada.totalMinor, 90000);
      final planes = await repo.planes();
      expect(planes.map((p) => p.id), ['p1']);
      expect(await repo.inscripcion('per1'), 50000);

      final [lista, cobro, productos, cuotas] = api.pedidos;
      expect(lista.queryParameters, {
        'estado': 'pendiente',
        'page': 1,
        'per_page': 20,
      });
      expect(cobro.method, 'POST');
      expect(cobro.path, '/api/v1/app/demo/ordenes/o1/liquidar');
      expect(cobro.data, {
        'metodo': 'efectivo',
        'referencia': null,
        'sucursal_id': 'su1',
      });
      expect(productos.path, '/api/v1/app/demo/productos');
      expect(cuotas.path, '/api/v1/app/demo/miembros/per1/cuotas');
    },
  );

  test(
    'vender lleva su clave: sin red, el reintento es la misma venta',
    () async {
      final api = _ApiVentas()..perderLaPrimera = true;
      final repo = VentasRepository(Dio()..httpClientAdapter = api, 'demo');

      await expectLater(
        repo.vender(
          personaId: 'per1',
          planId: 'p1',
          forma: 'tarjeta',
          sucursalId: 'su1',
        ),
        throwsA(isA<DioException>()),
      );
      await repo.vender(
        personaId: 'per1',
        planId: 'p1',
        forma: 'tarjeta',
        sucursalId: 'su1',
      );
      // Otra venta (otra forma de pago) es otro intento.
      await repo.vender(
        personaId: 'per1',
        planId: 'p1',
        forma: 'efectivo',
        sucursalId: 'su1',
      );

      final [primera, segunda, otra] = api.pedidos;
      expect(primera.path, '/api/v1/app/demo/ordenes');
      expect(primera.data['comprador_id'], 'per1');
      expect(primera.data['items'], [
        {'producto_id': 'p1', 'cantidad': 1},
      ]);
      expect(primera.data['liquidar']['metodo'], 'tarjeta');
      expect(primera.data['liquidar']['sucursal_id'], 'su1');
      expect(
        segunda.data['liquidar']['clave'],
        primera.data['liquidar']['clave'],
      );
      expect(
        otra.data['liquidar']['clave'],
        isNot(primera.data['liquidar']['clave']),
      );
    },
  );

  testWidgets('cobra lo pendiente con el precio de contado, confirmado', (
    tester,
  ) async {
    final ventas = _VentasFalsas();
    await _montar(tester, const PorCobrarScreen(), ventas: ventas, contado: 10);

    expect(find.text('Ana López'), findsOneWidget);
    await tester.tap(find.text('Ana López'));
    await tester.pumpAndSettle();

    // En efectivo, con el 10 % de contado: $900.00 en lugar de $1,000.00.
    expect(find.byKey(const Key('descuento-contado')), findsOneWidget);
    expect(find.text('Entra en la caja de Centro.'), findsOneWidget);
    await tester.tap(find.byKey(const Key('cobrar-orden')));
    await tester.pumpAndSettle();
    expect(find.textContaining('¿Registrar el pago de'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Cobrar \$900.00').last);
    await tester.pumpAndSettle();

    expect(ventas.hechas, ['cobrar:o1:efectivo:su1']);
    expect(find.textContaining('Pago registrado'), findsOneWidget);
    expect(find.text('Todo al día: nada por cobrar.'), findsOneWidget);
  });

  testWidgets('con varias sedes no se cobra hasta elegir una', (tester) async {
    final ventas = _VentasFalsas();
    await _montar(
      tester,
      const PorCobrarScreen(),
      ventas: ventas,
      sedes: const [
        SucursalAgenda(id: 'su1', nombre: 'Centro'),
        SucursalAgenda(id: 'su2', nombre: 'Norte'),
      ],
    );
    await tester.tap(find.text('Ana López'));
    await tester.pumpAndSettle();

    final boton = tester.widget<FilledButton>(
      find.byKey(const Key('cobrar-orden')),
    );
    expect(boton.onPressed, isNull);
    await tester.tap(find.byKey(const Key('sede-de-cobro')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Norte').last);
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<FilledButton>(find.byKey(const Key('cobrar-orden')))
          .onPressed,
      isNotNull,
    );
  });

  testWidgets('vende un plan con permanencia: acepta sus condiciones', (
    tester,
  ) async {
    final ventas = _VentasFalsas();
    await _montar(tester, const VenderScreen(), ventas: ventas);

    await tester.enterText(find.byKey(const Key('buscar-persona')), 'an');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ana López'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Anual'));
    await tester.pumpAndSettle();

    // Sin aceptar las condiciones, no se cobra.
    expect(
      tester.widget<FilledButton>(find.byKey(const Key('vender'))).onPressed,
      isNull,
    );
    await tester.tap(find.byKey(const Key('acepta-condiciones')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('vender')));
    await tester.pumpAndSettle();
    expect(find.textContaining('¿Vender Anual a Ana López'), findsOneWidget);
    await tester.tap(
      find.widgetWithText(FilledButton, 'Cobrar \$8,990.00').last,
    );
    await tester.pumpAndSettle();

    expect(ventas.hechas, ['vender:per1:p1:efectivo:su1:true']);
    expect(find.text('Listo: Ana López ya tiene Anual.'), findsOneWidget);
    // Ya cobrada: no queda lista para cobrar otra vez.
    expect(find.byKey(const Key('comprador')), findsNothing);
  });

  test('los permisos de la web: ver y gestionar órdenes, ver productos', () {
    expect(puedeCobrarOrdenes(_sesionVentas()), isTrue);
    expect(
      puedeCobrarOrdenes(_sesionVentas(permisos: ['ordenes.ver'])),
      isFalse,
    );
    expect(
      puedeVenderPlanes(
        _sesionVentas(permisos: ['ordenes.gestionar', 'productos.ver']),
      ),
      isTrue,
    );
    expect(
      puedeVenderPlanes(_sesionVentas(permisos: ['ordenes.gestionar'])),
      isFalse,
    );
  });
}
