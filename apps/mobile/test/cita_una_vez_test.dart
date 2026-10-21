import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/intento.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';

/// Una cita por intento (ADR 0201): si se perdió la respuesta, volver a mandar la
/// misma cita lleva la misma clave y el servidor devuelve la que ya agendó (con
/// «cualquier profesional» podía quedar otra con otra persona). Sin red real.

/// La primera petición se pierde (sin red); las siguientes responden como el API.
class _ApiSinRedUnaVez implements HttpClientAdapter {
  final pedidos = <Map<String, dynamic>>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(Map<String, dynamic>.from(options.data as Map));
    if (pedidos.length == 1) {
      throw DioException(
        requestOptions: options,
        type: DioExceptionType.connectionError,
      );
    }
    final ahora = DateTime.now().toUtc();
    return ResponseBody.fromString(
      jsonEncode({
        'data': {
          'id': 's1',
          'tipo': 'cita',
          'estado': 'confirmada',
          'inicia_en': ahora.toIso8601String(),
          'termina_en': ahora.add(const Duration(hours: 1)).toIso8601String(),
          'capacidad': 1,
          'ocupados': 1,
        },
      }),
      201,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

String? _clave(Map<String, dynamic> pedido) =>
    pedido['idempotency_key'] as String?;

void main() {
  test('la clave es la misma al repetir lo mismo y otra al cambiar', () {
    final intento = IntentoUnico();
    final primera = intento.clave({'hora': '10:00'});

    expect(primera, matches(RegExp(r'^[0-9a-f]{32}$')));
    expect(intento.clave({'hora': '10:00'}), primera);
    expect(intento.clave({'hora': '10:30'}), isNot(primera));
    intento.listo();
    expect(intento.clave({'hora': '10:30'}), isNot(primera));
  });

  test('cliente: reintentar la misma cita manda la misma clave', () async {
    final api = _ApiSinRedUnaVez();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');
    Future<void> agendar() => repo.agendarCita(
      servicioId: 'corte',
      sucursalId: 's1',
      profesionalId: null,
      iniciaEnLocal: '2030-01-08T10:00',
      duracionMinutos: 30,
    );

    await expectLater(agendar(), throwsA(isA<DioException>()));
    await agendar();
    // Ya respondió: la siguiente es otra cita.
    await agendar();

    expect(_clave(api.pedidos[0]), isNotNull);
    expect(_clave(api.pedidos[1]), _clave(api.pedidos[0]));
    expect(_clave(api.pedidos[2]), isNot(_clave(api.pedidos[0])));
  });

  test('equipo: reintentar la misma cita manda la misma clave', () async {
    final api = _ApiSinRedUnaVez();
    final repo = AgendaRepository(Dio()..httpClientAdapter = api, 'demo');
    final cita = NuevaCita(
      personaId: 'a1',
      ofertaId: 'o1',
      sucursalId: 's1',
      instructorId: 'p1',
      inicia: DateTime(2030, 1, 8, 10),
      duracionMinutos: 30,
    );

    await expectLater(repo.agendarCita(cita), throwsA(isA<DioException>()));
    await repo.agendarCita(cita);

    expect(_clave(api.pedidos[0]), isNotNull);
    expect(_clave(api.pedidos[1]), _clave(api.pedidos[0]));
  });
}
