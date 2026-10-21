import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/compras_mostrador.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/compras_mostrador.dart';

/// «Compras en mostrador» en la app (ADR 0145), como en la web: lo que compró en
/// recepción con lo que llevó, cuándo, dónde y cuánto pagó (en centavos, sin punto
/// flotante); las anuladas tachadas y, si nunca compró, la sección no aparece.
/// Datos sintéticos, sin red real.

/// API falso: anota cada petición y responde una página de tickets (o ninguna).
class _ApiMostrador implements HttpClientAdapter {
  _ApiMostrador({this.conCompras = true});

  final bool conCompras;
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    final pagina = (options.queryParameters['page'] as int?) ?? 1;
    return ResponseBody.fromString(
      jsonEncode({
        'data': conCompras ? [_ticket(pagina)] : <Object>[],
        'meta': {
          'total': conCompras ? 2 : 0,
          'page': pagina,
          'per_page': 10,
          'ultima_pagina': conCompras ? 2 : 1,
        },
      }),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Map<String, dynamic> _ticket(int pagina) => pagina == 1
    ? {
        'id': '01JVENTA0000000000007K2M9QXA',
        'folio': '7K2M9QXA',
        'creado_en': '2026-10-20T18:05:00-06:00',
        'sucursal': 'Roma Norte',
        'servicio': null,
        'lineas': [
          {
            'articulo': 'Agua',
            'cantidad': 2,
            'extras': <String>[],
            'subtotal_minor': 5000,
          },
          {
            'articulo': 'Licuado',
            'cantidad': 1,
            'extras': ['Proteína'],
            'subtotal_minor': 8500,
          },
        ],
        'total_minor': 13500,
        'propina_minor': 1500,
        'cobrado_minor': 15000,
        'recibido_minor': 20000,
        'cambio_minor': 5000,
        'moneda': 'MXN',
        'metodo_pago': 'mixto',
        'partes': [
          {'metodo': 'efectivo', 'monto_minor': 10000},
          {'metodo': 'tarjeta', 'monto_minor': 5000},
        ],
        'anulada': false,
      }
    : {
        'id': '01JVENTA00000000000000ANULAD',
        'folio': '00ANULAD',
        'creado_en': '2026-10-02T10:00:00-06:00',
        'sucursal': null,
        'lineas': [
          {'articulo': 'Toalla', 'cantidad': 1, 'extras': <String>[]},
        ],
        'total_minor': 8000,
        'propina_minor': 0,
        'moneda': 'MXN',
        'metodo_pago': 'efectivo',
        'anulada': true,
      };

const _ana = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Ana',
  rol: 'miembro',
  desfaseNegocioMin: -360,
);

Future<_ApiMostrador> _montar(
  WidgetTester tester, {
  bool conCompras = true,
}) async {
  final api = _ApiMostrador(conCompras: conCompras);
  tester.view.physicalSize = const Size(420, 1200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(_ana),
        cuentaRepositoryProvider.overrideWithValue(
          CuentaRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
      ],
      child: const MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(child: ComprasMostradorSeccion()),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return api;
}

void main() {
  test('el ticket: lo que llevó, cuánto pagó con propina y cómo', () {
    final c = CompraMostrador.desdeJson(_ticket(1));

    expect(c.folio, '7K2M9QXA');
    expect(c.resumen, '2 × Agua, 1 × Licuado (Proteína)');
    // Productos y propina, en centavos (el servicio de una cita va aparte).
    expect(c.pagadoMinor, 15000);
    expect(c.metodoTexto, 'Varias formas');
    expect(c.anulada, isFalse);
    expect(
      c.detalle(desfaseMin: -360),
      'mar 20 oct · 18:05 · Roma Norte · Ticket 7K2M9QXA',
    );
  });

  test('sin moneda en el ticket, la del negocio', () {
    final c = CompraMostrador.desdeJson({
      'id': 'v1',
      'folio': 'F',
      'lineas': const <Object>[],
      'total_minor': 100,
    }, moneda: 'COP');

    expect(c.moneda, 'COP');
    expect(c.propinaMinor, 0);
    expect(c.lineas, isEmpty);
  });

  test('pide sus compras del mostrador por páginas', () async {
    final api = _ApiMostrador();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    final pagina = await repo.comprasMostrador(pagina: 2);

    expect(api.pedidos.single.method, 'GET');
    expect(api.pedidos.single.path, '/api/v1/app/demo/mi/compras-mostrador');
    expect(api.pedidos.single.queryParameters, {'page': 2, 'per_page': 10});
    expect(pagina.compras.single.anulada, isTrue);
    expect(pagina.hayMas, isFalse);
  });

  testWidgets('muestra sus tickets, la propina y carga los demás', (
    tester,
  ) async {
    final api = await _montar(tester);

    expect(find.text('Compras en mostrador'), findsOneWidget);
    expect(find.text('2 × Agua, 1 × Licuado (Proteína)'), findsOneWidget);
    expect(find.text('\$150.00'), findsOneWidget);
    expect(find.text('Con \$15.00 de propina'), findsOneWidget);

    await tester.tap(find.text('Ver más'));
    await tester.pumpAndSettle();

    expect(api.pedidos.length, 2);
    expect(find.text('1 × Toalla'), findsOneWidget);
    expect(find.text('Anulada'), findsOneWidget);
    expect(find.text('Ver más'), findsNothing);
  });

  testWidgets('si nunca compró en el mostrador, no aparece', (tester) async {
    await _montar(tester, conCompras: false);

    expect(find.text('Compras en mostrador'), findsNothing);
  });
}
