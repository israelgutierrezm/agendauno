import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/config/app_config.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';
import 'package:agendauno/main.dart' show colorDeLaApp;

/// La app de marca blanca compilada con su archivo (ADR 0111 y 0208). Solo corre
/// así (el CI lo hace con el de ejemplo):
///
///   flutter test test/marca_blanca_test.dart \
///     --dart-define-from-file=configuraciones/marca_blanca/ejemplo.json
///
/// Sin el archivo (la suite de siempre, en las dos apps oficiales) se salta.
const _sinMarcaBlanca = AppConfig.marcaBlanca
    ? null
    : 'Solo con --dart-define-from-file=configuraciones/marca_blanca/ejemplo.json';

/// API falsa: guarda lo que se le pidió; todo responde 404.
class _Api implements HttpClientAdapter {
  final List<RequestOptions> peticiones = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    return ResponseBody.fromString(
      jsonEncode({'message': 'Estudio no encontrado.'}),
      404,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

(ProviderContainer, _Api) _contenedor() {
  FlutterSecureStorage.setMockInitialValues({});
  final c = ProviderContainer(
    overrides: [almacenSesionProvider.overrideWithValue(AlmacenSesion())],
  );
  addTearDown(c.dispose);
  final api = _Api();
  c.read(dioProvider).httpClientAdapter = api;
  return (c, api);
}

void main() {
  test('es la app de su negocio, con su nombre y su id', () {
    expect(AppConfig.negocioFijo, 'ejemplo');
    expect(AppConfig.nombreApp, 'Estudio Ejemplo');
    expect(AppConfig.idAndroid, 'mx.ejemplo.estudio');
    // Sin sesión, el tema ya lleva su color (COLOR_MARCA).
    expect(colorDeLaApp(null), const Color(0xFF0F766E));
  }, skip: _sinMarcaBlanca);

  test('cada petición dice qué negocio abre (X-App-Negocio)', () async {
    final (c, api) = _contenedor();
    await c
        .read(dioProvider)
        .get<Object>('/api/v1/app/ejemplo/marca')
        .catchError((_) => Response<Object>(requestOptions: RequestOptions()));

    expect(api.peticiones.single.headers['X-App-Negocio'], 'ejemplo');
    expect(api.peticiones.single.headers['X-App-Producto'], 'agendauno');
  }, skip: _sinMarcaBlanca);

  test('no busca el negocio en la app del otro producto', () async {
    final (c, api) = _contenedor();

    expect(
      await c.read(sesionProvider.notifier).enOtroProducto('barberia'),
      isNull,
    );
    expect(api.peticiones, isEmpty);
  }, skip: _sinMarcaBlanca);

  testWidgets('el acceso es el de su negocio; si no está, a quién acudir', (
    tester,
  ) async {
    final (c, api) = _contenedor();
    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: c,
        child: const MaterialApp(home: LoginScreen()),
      ),
    );
    expect(find.text('Estudio Ejemplo'), findsOneWidget);
    expect(find.byKey(const Key('direccion-negocio')), findsNothing);
    expect(
      find.text('Aviso de privacidad de Estudio Ejemplo'),
      findsOneWidget,
    );

    await tester.enterText(find.widgetWithText(TextField, 'Correo'), 'a@b.mx');
    await tester.enterText(find.widgetWithText(TextField, 'Contraseña'), 'x');
    await tester.tap(find.widgetWithText(FilledButton, 'Entrar'));
    await tester.pumpAndSettle();

    // Solo pidió entrar a su negocio (nada al otro producto).
    expect(api.peticiones.single.uri.path, '/api/v1/app/ejemplo/login');
    expect(
      find.text(
        'Esta app no está disponible por ahora. Contacta a Estudio Ejemplo.',
      ),
      findsOneWidget,
    );
  }, skip: _sinMarcaBlanca != null);
}
