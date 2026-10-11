import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/agendar_cita_sheet.dart';

/// Antes de confirmar una cita se ve dónde será (M06): la sede, su zona y dirección,
/// y cómo llegar. Datos sintéticos.

const _opciones = {
  'servicios': [
    {'id': 'corte', 'nombre': 'Corte', 'duracion_minutos': 30},
  ],
  'sucursales': [
    {
      'id': 's1',
      'nombre': 'Centro',
      'region': 'Zona Centro',
      'direccion': 'Calle Uno 10',
      'mapa_url': 'https://maps.google.com/?q=Calle+Uno+10',
    },
  ],
  'instructores': [
    {'id': 'p1', 'nombre': 'Beto'},
  ],
};

class _Api implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final opciones = options.path.endsWith('/mi/citas/opciones');
    return ResponseBody.fromString(
      jsonEncode(opciones ? {'data': _opciones} : {'message': 'No'}),
      opciones ? 200 : 404,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  testWidgets('dice dónde será la cita y cómo llegar', (tester) async {
    tester.view.physicalSize = const Size(390, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(
            const Sesion(
              slug: 'demo',
              bearer: 't',
              nombre: 'Vale',
              rol: 'miembro',
            ),
          ),
          cuentaRepositoryProvider.overrideWithValue(
            CuentaRepository(Dio()..httpClientAdapter = _Api(), 'demo'),
          ),
        ],
        child: const MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(child: AgendarCitaSheet()),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final donde = find.byKey(const Key('donde-sera'));
    expect(donde, findsOneWidget);
    expect(
      find.descendant(
        of: donde,
        matching: find.text('Zona Centro · Calle Uno 10'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: donde, matching: find.text('Cómo llegar')),
      findsOneWidget,
    );
  });
}
