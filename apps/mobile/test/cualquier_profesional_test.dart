import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';

/// «Cualquier profesional disponible»: sin profesional no se manda `instructor_id`
/// (el negocio asigna a quien esté libre) y la respuesta dice quién atenderá.
/// Datos sintéticos.

/// Responde como el API y guarda lo que se le pidió.
class _Api implements HttpClientAdapter {
  final List<RequestOptions> peticiones = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    final Object data = options.path.endsWith('/mi/citas/dias')
        ? [
            {'fecha': '2030-01-06', 'abierto': false},
            {'fecha': '2030-01-07', 'abierto': true},
          ]
        : options.method == 'POST'
        ? {
            'estado': 'pendiente_pago',
            'profesional': {'id': 'p2', 'nombre': 'Carla'},
          }
        : {
            'slots': [
              {
                'inicia': '2026-10-05T16:00:00+00:00',
                'termina': '2026-10-05T16:30:00+00:00',
                'profesionales': ['p1', 'p2'],
              },
            ],
          };

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
  late _Api api;
  late CuentaRepository repo;

  setUp(() {
    api = _Api();
    repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');
  });

  test('sin profesional pide los huecos de todo el equipo', () async {
    final horarios = await repo.horariosLibres(
      profesionalId: null,
      sucursalId: 's1',
      fecha: '2026-10-05',
      duracionMinutos: 30,
    );

    expect(horarios, ['2026-10-05T16:00:00+00:00']);
    expect(
      api.peticiones.single.queryParameters.containsKey('instructor_id'),
      isFalse,
    );
  });

  test('con profesional lo manda', () async {
    await repo.horariosLibres(
      profesionalId: 'p1',
      sucursalId: 's1',
      fecha: '2026-10-05',
      duracionMinutos: 30,
    );

    expect(api.peticiones.single.queryParameters['instructor_id'], 'p1');
  });

  test('al agendar sin profesional devuelve a quién se asignó', () async {
    final cita = await repo.agendarCita(
      servicioId: 'o1',
      sucursalId: 's1',
      profesionalId: null,
      iniciaEnLocal: '2026-10-05T10:00',
      duracionMinutos: 30,
    );

    expect(cita.estado, 'pendiente_pago');
    expect(cita.profesional, 'Carla');
    final enviado = api.peticiones.single.data as Map<String, dynamic>;
    expect(enviado.containsKey('instructor_id'), isFalse);
    expect(enviado.containsKey('nota'), isFalse);
  });

  test('pide los días con atención y devuelve solo los abiertos', () async {
    final dias = await repo.diasConAtencion(
      sucursalId: 's1',
      desde: '2030-01-06',
      profesionalId: 'p1',
    );

    final pedido = api.peticiones.single;
    expect(pedido.path, endsWith('/mi/citas/dias'));
    expect(pedido.queryParameters['instructor_id'], 'p1');
    expect(pedido.queryParameters['dias'], 62);
    expect(dias, {'2030-01-07'});
  });

  test('manda la nota para el negocio si la escribió', () async {
    await repo.agendarCita(
      servicioId: 'o1',
      sucursalId: 's1',
      profesionalId: 'p1',
      iniciaEnLocal: '2026-10-05T10:00',
      duracionMinutos: 30,
      nota: 'Es mi primera vez.',
    );

    final enviado = api.peticiones.single.data as Map<String, dynamic>;
    expect(enviado['nota'], 'Es mi primera vez.');
  });
}
