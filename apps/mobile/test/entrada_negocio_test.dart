import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/config/app_config.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/features/auth/data/directorio.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';

/// La entrada de la app, como la web: buscar el negocio por su nombre en el
/// directorio de ESTE producto y entrar de nuevo con un toque a los que se abrieron
/// en el teléfono (los últimos 5, cada producto con su lista). Sin red real.

class _ApiDirectorio implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    final q = (options.queryParameters['q'] ?? '') as String;
    return ResponseBody.fromString(
      jsonEncode({
        'data': [
          if (q.isEmpty || 'estudio luna'.contains(q.toLowerCase()))
            {
              'slug': 'luna',
              'nombre': 'Estudio Luna',
              'ciudad': 'Puebla',
              'pais': 'MX',
              'producto': 'agendauno',
            },
          if (q.isEmpty)
            {'slug': 'sol', 'nombre': 'Sol Fit', 'ciudad': null, 'pais': 'CO'},
        ],
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

void main() {
  test('busca en el directorio de su producto', () async {
    final api = _ApiDirectorio();
    final repo = DirectorioRepository(Dio()..httpClientAdapter = api);

    final todos = await repo.buscar('');
    final luna = await repo.buscar(' luna ');

    expect(todos.map((n) => n.slug), ['luna', 'sol']);
    expect(todos.first.lugar, 'Puebla · MX');
    expect(luna.single.nombre, 'Estudio Luna');
    expect(api.pedidos.first.path, '/api/v1/directorio');
    expect(api.pedidos.first.queryParameters, {
      'producto': AppConfig.producto.clave,
    });
    expect(api.pedidos.last.queryParameters['q'], 'luna');
  });

  test('recuerda los últimos 5, el más reciente primero, y olvida', () async {
    FlutterSecureStorage.setMockInitialValues({});
    final recientes = NegociosRecientes();
    final hoy = DateTime(2026, 10, 25, 9);

    for (var i = 0; i < 6; i++) {
      await recientes.recordar(
        NegocioDirectorio(slug: 'n$i', nombre: 'Negocio $i'),
        ahora: hoy.add(Duration(minutes: i)),
      );
    }
    // Volver a uno lo sube y conserva lo que ya se sabía de él.
    await recientes.recordar(
      const NegocioDirectorio(slug: 'n3', nombre: 'Negocio 3', ciudad: 'León'),
      ahora: hoy.add(const Duration(hours: 1)),
    );
    await recientes.recordar(
      const NegocioDirectorio(slug: 'n3', nombre: 'Negocio 3'),
      ahora: hoy.add(const Duration(hours: 2)),
    );

    final lista = await recientes.leer();
    expect(lista.map((n) => n.slug), ['n3', 'n5', 'n4', 'n2', 'n1']);
    expect(lista.first.ciudad, 'León');
    expect((await recientes.olvidar('n5')).map((n) => n.slug), [
      'n3',
      'n4',
      'n2',
      'n1',
    ]);
  });

  test('cada producto guarda su lista', () async {
    FlutterSecureStorage.setMockInitialValues({
      'otro.negocios-recientes.v1': jsonEncode([
        {'slug': 'ajeno', 'nombre': 'Ajeno', 'visitado_en': 1},
      ]),
    });

    expect(await NegociosRecientes().leer(), isEmpty);
  });

  testWidgets('elige un reciente o lo busca por nombre y queda su dirección', (
    tester,
  ) async {
    FlutterSecureStorage.setMockInitialValues({
      '${AppConfig.producto.clave}.negocios-recientes.v1': jsonEncode([
        {'slug': 'barberia', 'nombre': 'Barbería Don Beto', 'visitado_en': 5},
      ]),
    });
    final api = _ApiDirectorio();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          dioProvider.overrideWithValue(Dio()..httpClientAdapter = api),
        ],
        child: const MaterialApp(home: LoginScreen()),
      ),
    );
    await tester.pumpAndSettle();

    // Un toque en el reciente deja su dirección.
    expect(find.text('Tus negocios'), findsOneWidget);
    await tester.tap(find.text('Barbería Don Beto'));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('direccion-negocio')))
          .controller
          ?.text,
      'barberia',
    );
    expect(find.text('Entras a Barbería Don Beto'), findsOneWidget);

    // Buscar por nombre: el directorio de esta app.
    await tester.tap(find.byKey(const Key('buscar-negocio')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('buscar-negocio-nombre')),
      'luna',
    );
    await tester.pump(const Duration(milliseconds: 350));
    await tester.pumpAndSettle();
    expect(find.text('Sol Fit'), findsNothing);
    await tester.tap(find.text('Estudio Luna'));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('direccion-negocio')))
          .controller
          ?.text,
      'luna',
    );
    expect(find.text('Entras a Estudio Luna'), findsOneWidget);

    // Quitar el reciente de la lista.
    await tester.tap(find.byTooltip('Quitar de la lista'));
    await tester.pumpAndSettle();
    expect(find.text('Tus negocios'), findsNothing);
  });
}
