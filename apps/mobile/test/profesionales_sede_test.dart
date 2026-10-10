import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/agendar_cita_sheet.dart';

/// Al agendar una cita solo se ofrece a quien atiende en la sede elegida
/// (`instructores[].sucursales`, las sedes de su horario), como en la web: con
/// alguien de otra sede no habría horarios. Sin la lista (API anterior), atiende en
/// todas. Datos sintéticos.

const _opciones = {
  'servicios': [
    {'id': 'corte', 'nombre': 'Corte', 'duracion_minutos': 30},
  ],
  'sucursales': [
    {'id': 's1', 'nombre': 'Centro'},
    {'id': 's2', 'nombre': 'Norte'},
  ],
  'instructores': [
    {
      'id': 'p1',
      'nombre': 'Beto',
      'sucursales': ['s1'],
    },
    {
      'id': 'p2',
      'nombre': 'Carla',
      'sucursales': ['s2'],
    },
    // Sin la lista: atiende en todas.
    {'id': 'p3', 'nombre': 'Dani'},
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

List<String> _nombres(List<OpcionCita> lista) =>
    lista.map((p) => p.nombre).toList();

void main() {
  test('quien atiende en cada sede', () {
    final instructores = (_opciones['instructores']! as List)
        .map((e) => OpcionCita.desdeJson(e as Map<String, dynamic>))
        .toList();
    final opciones = OpcionesCita(
      servicios: const [],
      sucursales: const [],
      profesionales: instructores,
    );

    expect(instructores.last.sucursales, isNull);
    expect(_nombres(opciones.profesionalesEn('s1')), ['Beto', 'Dani']);
    expect(_nombres(opciones.profesionalesEn('s2')), ['Carla', 'Dani']);
    // Sin sede elegida, todo el equipo.
    expect(_nombres(opciones.profesionalesEn(null)), ['Beto', 'Carla', 'Dani']);
    // Con la lista vacía (no tiene horario en ninguna), en ninguna.
    expect(
      OpcionCita.desdeJson({
        'id': 'p4',
        'nombre': 'Eva',
        'sucursales': <String>[],
      }).atiendeEn('s1'),
      isFalse,
    );
  });

  testWidgets('al elegir sede solo se ofrece a quien atiende ahí', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1400);
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
        child: const MaterialApp(home: Scaffold(body: AgendarCitaSheet())),
      ),
    );
    await tester.pumpAndSettle();

    Future<void> elegirSede(String sede) async {
      await tester.tap(find.byType(DropdownButtonFormField<OpcionCita>).at(1));
      await tester.pumpAndSettle();
      await tester.tap(find.text(sede).last);
      await tester.pumpAndSettle();
    }

    await elegirSede('Norte');
    await tester.tap(find.text('Elegir a alguien específico'));
    await tester.pumpAndSettle();
    expect(find.text('Carla'), findsOneWidget);
    expect(find.text('Dani'), findsOneWidget);
    expect(find.text('Beto'), findsNothing);

    // Carla no atiende en Centro: se parte de nuevo con el equipo de Centro.
    await elegirSede('Centro');
    expect(find.text('Carla'), findsNothing);
    await tester.tap(find.text('Elegir a alguien específico'));
    await tester.pumpAndSettle();
    expect(find.text('Beto'), findsOneWidget);
    expect(find.text('Dani'), findsOneWidget);
    expect(find.text('Carla'), findsNothing);
  });
}
