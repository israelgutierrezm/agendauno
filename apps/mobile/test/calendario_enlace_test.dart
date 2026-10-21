import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/google/google_auth.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/perfil/presentation/perfil_screen.dart';

/// El enlace privado de su calendario (iCal) en Mi perfil, como en la web: copiarlo
/// (Google Calendar lo pide como URL) y generar uno nuevo si lo compartió, avisando
/// antes que el anterior deja de funcionar. Datos sintéticos, sin red real.

/// API falso del calendario: cada `regenerar` da otro token.
class _ApiCalendario implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];
  var _token = 'tok1';

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    if (options.path.endsWith('/regenerar')) {
      _token = 'tok2';
    }
    final url =
        'https://api.example.test/api/v1/app/demo/calendario/$_token.ics';
    return ResponseBody.fromString(
      jsonEncode({
        'data': {
          'url': url,
          'webcal': url.replaceFirst('https://', 'webcal://'),
          'evento': url.replaceFirst('.ics', '/{evento}.ics'),
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

class _SinGoogle implements GoogleAuth {
  @override
  bool get disponible => false;

  @override
  Future<String?> idToken() async => null;
}

const _ana = Sesion(slug: 'demo', bearer: 't', nombre: 'Ana', rol: 'miembro');

(ProviderContainer, _ApiCalendario) _contenedor() {
  final c = ProviderContainer(
    overrides: [
      almacenSesionProvider.overrideWithValue(AlmacenSesion()),
      sesionInicialProvider.overrideWithValue(_ana),
      googleAuthProvider.overrideWithValue(_SinGoogle()),
    ],
  );
  final api = _ApiCalendario();
  c.read(dioProvider).httpClientAdapter = api;
  return (c, api);
}

void main() {
  test('el enlace para copiar y uno nuevo que reemplaza al anterior', () async {
    final (c, api) = _contenedor();
    addTearDown(c.dispose);
    final sesion = c.read(sesionProvider.notifier);

    final actual = await sesion.urlCalendario();
    final nuevo = await sesion.regenerarCalendario();

    expect(actual, endsWith('/calendario/tok1.ics'));
    expect(nuevo, endsWith('/calendario/tok2.ics'));
    expect(api.pedidos[0].method, 'GET');
    expect(api.pedidos[0].path, '/api/v1/app/demo/yo/calendario');
    expect(api.pedidos[1].method, 'POST');
    expect(api.pedidos[1].path, '/api/v1/app/demo/yo/calendario/regenerar');
    // Suscribirse con un toque sigue usando webcal://.
    expect(await sesion.enlaceCalendario(), startsWith('webcal://'));
  });

  testWidgets(
    'copia el enlace; uno nuevo se confirma antes y se puede cancelar',
    (tester) async {
      tester.view.physicalSize = const Size(800, 2800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final (c, api) = _contenedor();
      addTearDown(c.dispose);
      // El portapapeles del teléfono: lo que se copió.
      String? copiado;
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        (llamada) async {
          if (llamada.method == 'Clipboard.setData') {
            copiado = (llamada.arguments as Map)['text'] as String?;
          }
          return null;
        },
      );
      addTearDown(
        () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
          SystemChannels.platform,
          null,
        ),
      );
      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: c,
          child: const MaterialApp(home: PerfilScreen()),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('copiar-calendario')));
      await tester.pumpAndSettle();
      expect(copiado, endsWith('/calendario/tok1.ics'));
      expect(find.text('Enlace copiado.'), findsOneWidget);
      api.pedidos.clear();
      // Se va el aviso (los avisos salen de uno en uno).
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('regenerar-calendario')));
      await tester.pumpAndSettle();
      expect(find.textContaining('dejará de funcionar'), findsOneWidget);

      // Cancelar no cambia nada.
      await tester.tap(find.text('Cancelar'));
      await tester.pumpAndSettle();
      expect(api.pedidos, isEmpty);

      await tester.tap(find.byKey(const Key('regenerar-calendario')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('confirmar-regenerar-calendario')));
      await tester.pumpAndSettle();

      expect(
        api.pedidos.single.path,
        '/api/v1/app/demo/yo/calendario/regenerar',
      );
      expect(find.textContaining('tu enlace es nuevo'), findsOneWidget);
      // El nuevo queda copiado para agregarlo otra vez.
      expect(copiado, endsWith('/calendario/tok2.ics'));
    },
  );
}
