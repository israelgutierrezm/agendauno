import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/bandeja/data/bandeja_repository.dart';

/// La bandeja del equipo en la app (ADR 0200): lee las conversaciones como las da el
/// API y contesta por las mismas rutas que la web. Sin red real.

class _ApiDeBandeja implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    final cuerpo = options.path.endsWith('/conversaciones/c1')
        ? {
            'data': {
              'id': 'c1',
              'canal': 'whatsapp',
              'contacto': 'Ana',
              'telefono': '+5215512345678',
              'persona': {'id': 'p1', 'nombre': 'Ana Pérez'},
              'no_leidos': 0,
              'ultimo_texto': '¿Hay clase?',
              'ultimo_mensaje_en': '2030-01-08T16:00:00Z',
              'puede_contestar': true,
              'contestar_hasta': '2030-01-09T16:00:00Z',
              'como_agente': false,
              'mensajes': [
                {
                  'id': 'm1',
                  'direccion': 'entrante',
                  'tipo': 'imagen',
                  'texto': null,
                  'estado': 'recibido',
                  'enviado_en': '2030-01-08T16:00:00Z',
                  'enviado_por': null,
                  'archivo': {
                    'nombre': 'foto.jpg',
                    'mime': 'image/jpeg',
                    'bytes': 10,
                    'listo': true,
                    'en_linea': true,
                  },
                },
              ],
            },
          }
        : {'data': <String, dynamic>{}};
    return ResponseBody.fromString(
      jsonEncode(cuerpo),
      201,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test(
    'lee la conversación: quién es, si se puede contestar y sus archivos',
    () async {
      final api = _ApiDeBandeja();
      final repo = BandejaRepository(Dio()..httpClientAdapter = api, 'demo');

      final c = await repo.conversacion('c1');

      expect(c.titulo, 'Ana Pérez');
      expect(c.puedeContestar, isTrue);
      expect(c.mensajes.single.entrante, isTrue);
      expect(c.mensajes.single.archivo?.enLinea, isTrue);
      expect(
        api.pedidos.single.path,
        '/api/v1/app/demo/bandeja/conversaciones/c1',
      );
    },
  );

  test('contesta con texto y manda una foto con su pie', () async {
    final api = _ApiDeBandeja();
    final repo = BandejaRepository(Dio()..httpClientAdapter = api, 'demo');

    await repo.contestar('c1', 'Sí, a las 10.');
    await repo.mandarFoto('c1', [1, 2, 3], 'horario.jpg', pie: 'El horario');

    expect(
      api.pedidos[0].path,
      '/api/v1/app/demo/bandeja/conversaciones/c1/mensajes',
    );
    expect(api.pedidos[0].data, {'texto': 'Sí, a las 10.'});
    expect(
      api.pedidos[1].path,
      '/api/v1/app/demo/bandeja/conversaciones/c1/archivos',
    );
    final campos = (api.pedidos[1].data as FormData).fields;
    expect(
      campos.map((c) => '${c.key}=${c.value}'),
      contains('texto=El horario'),
    );
  });
}
