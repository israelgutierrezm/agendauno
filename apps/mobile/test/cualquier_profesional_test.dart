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
    final data = options.method == 'POST'
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
  });
}
