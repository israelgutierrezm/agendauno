import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/network/auth_token.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/network/participante_en_uso.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// Familias (ADR 0123): la cuenta actúa por una persona a su cargo mandando
/// `X-Participante` solo a su cuenta (`/mi/…`); si el servidor ya no la autoriza,
/// vuelve a la cuenta propia. El módulo viene en la sesión. Datos sintéticos.

/// API falso: guarda lo que se le pidió; `/mi/perfil` niega a la persona a cargo.
class _Api implements HttpClientAdapter {
  final List<RequestOptions> peticiones = [];
  bool negar = false;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    final (int estado, Object cuerpo) = negar
        ? (403, {'code': 'GUARDIAN_NOT_AUTHORIZED'})
        : (200, {'data': <String, dynamic>{}});
    return ResponseBody.fromString(
      jsonEncode(cuerpo),
      estado,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test('X-Participante solo va a la cuenta del alumno', () async {
    final api = _Api();
    final contenedor = ProviderContainer();
    addTearDown(contenedor.dispose);
    contenedor.read(authTokenProvider.notifier).establecer('token');
    final dio = contenedor.read(dioProvider)..httpClientAdapter = api;

    contenedor.read(participanteEnUsoProvider.notifier).elegir('p-sofia');
    await dio.get<void>('/api/v1/app/demo/mi/perfil');
    await dio.get<void>('/api/v1/app/demo/yo');

    expect(api.peticiones[0].headers['X-Participante'], 'p-sofia');
    expect(api.peticiones[1].headers.containsKey('X-Participante'), isFalse);
  });

  test('si ya no está autorizada, vuelve a la cuenta propia', () async {
    final api = _Api()..negar = true;
    final contenedor = ProviderContainer();
    addTearDown(contenedor.dispose);
    contenedor.read(authTokenProvider.notifier).establecer('token');
    final dio = contenedor.read(dioProvider)..httpClientAdapter = api;
    contenedor.read(participanteEnUsoProvider.notifier).elegir('p-sofia');

    await expectLater(
      dio.get<void>('/api/v1/app/demo/mi/perfil'),
      throwsA(isA<DioException>()),
    );
    expect(contenedor.read(participanteEnUsoProvider), isNull);
  });

  test('con otra sesión, vuelve a la cuenta propia', () {
    final contenedor = ProviderContainer();
    addTearDown(contenedor.dispose);
    contenedor.read(authTokenProvider.notifier).establecer('uno');
    contenedor.read(participanteEnUsoProvider.notifier).elegir('p-sofia');

    contenedor.read(authTokenProvider.notifier).establecer('dos');

    expect(contenedor.read(participanteEnUsoProvider), isNull);
  });

  test('la sesión dice qué módulos encendió el superadministrador', () {
    final sesion = Sesion.desdeJson(
      'demo',
      'token',
      {'nombre': 'Mamá', 'rol': 'miembro'},
      {
        'modulos': ['familias'],
      },
    );
    expect(sesion.tieneModulo('familias'), isTrue);
    expect(
      Sesion.desdeAlmacen(sesion.aJson())?.tieneModulo('familias'),
      isTrue,
    );
    expect(
      Sesion.desdeJson('demo', 'token', {
        'rol': 'miembro',
      }).tieneModulo('familias'),
      isFalse,
    );
  });
}
