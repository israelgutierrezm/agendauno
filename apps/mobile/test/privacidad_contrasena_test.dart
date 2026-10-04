import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';

/// Ver mis datos y pedir la baja se confirman con la contraseña: va en el cuerpo
/// (nunca en la URL) y el servidor la valida. Datos sintéticos.
class _Api implements HttpClientAdapter {
  final List<RequestOptions> peticiones = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    final Object data = options.path.endsWith('/mi/datos')
        ? {
            'persona': {'nombre': 'Vale'},
          }
        : {'recibe_promociones': true, 'baja': null};
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

  test('ver mis datos manda la contraseña en el cuerpo, por POST', () async {
    final datos = await repo.misDatos('secreta');

    final peticion = api.peticiones.single;
    expect(peticion.method, 'POST');
    expect(peticion.path, endsWith('/mi/datos'));
    expect(peticion.uri.query, isEmpty);
    expect(peticion.data, {'password': 'secreta'});
    expect((datos['persona'] as Map)['nombre'], 'Vale');
  });

  test('pedir la baja manda el motivo y la contraseña', () async {
    await repo.solicitarBaja('Me mudo', 'secreta');

    expect(api.peticiones.single.data, {
      'motivo': 'Me mudo',
      'password': 'secreta',
    });
  });
}
