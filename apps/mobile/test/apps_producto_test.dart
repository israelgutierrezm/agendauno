import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/config/app_config.dart';
import 'package:agendauno/core/config/producto_app.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/presentation/actualizar_app_screen.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';

/// Dos apps oficiales desde el mismo código (ADR 0111): sin sabor es la de AgendaUno;
/// cada petición dice de qué app viene; si el negocio es del otro producto, se dice
/// qué app descargar. Una app de marca blanca abre directo en su negocio. Datos
/// sintéticos.

/// API falso: el login solo encuentra a «fluo»; «barberia» es de TurnoUno (su
/// /marca solo responde a esa app). Guarda lo que se le pidió.
class _ApiProductos implements HttpClientAdapter {
  final List<RequestOptions> peticiones = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    final barberiaDeTurnoUno =
        options.headers['X-App-Producto'] == 'turnouno' &&
        options.uri.path.contains('/barberia/');
    final (int estado, Object cuerpo) = options.uri.path.endsWith('/marca')
        ? barberiaDeTurnoUno
              ? (
                  200,
                  {
                    'data': {'producto': 'turnouno'},
                  },
                )
              : (404, {'message': 'Estudio no encontrado.'})
        : options.uri.path.endsWith('/login') &&
              options.uri.path.contains('/fluo/')
        ? (
            200,
            {
              'data': {
                'token': 'token-fluo',
                'usuario': {'nombre': 'Ana', 'rol': 'miembro'},
              },
            },
          )
        : (404, {'message': 'Estudio no encontrado.'});
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

(ProviderContainer, _ApiProductos) _contenedor() {
  FlutterSecureStorage.setMockInitialValues({});
  final c = ProviderContainer(
    overrides: [almacenSesionProvider.overrideWithValue(AlmacenSesion())],
  );
  addTearDown(c.dispose);
  final api = _ApiProductos();
  c.read(dioProvider).httpClientAdapter = api;
  return (c, api);
}

Future<void> _entrar(
  WidgetTester tester,
  ProviderContainer c,
  LoginScreen pantalla, {
  String? negocio,
}) async {
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: c,
      child: MaterialApp(home: pantalla),
    ),
  );
  if (negocio != null) {
    await tester.enterText(find.byKey(const Key('direccion-negocio')), negocio);
  }
  await tester.enterText(find.widgetWithText(TextField, 'Correo'), 'a@b.mx');
  await tester.enterText(find.widgetWithText(TextField, 'Contraseña'), 'x');
  await tester.tap(find.widgetWithText(FilledButton, 'Entrar'));
  await tester.pumpAndSettle();
}

void main() {
  test('sin sabor es la app oficial de AgendaUno', () {
    expect(AppConfig.producto, same(ProductoApp.agendaUno));
    expect(AppConfig.nombreApp, 'AgendaUno');
    expect(AppConfig.idAndroid, 'com.agendauno.app');
    expect(AppConfig.marcaBlanca, isFalse);
    expect(ProductoApp.agendaUno.otro, same(ProductoApp.turnoUno));
    expect(ProductoApp.turnoUno.otro, same(ProductoApp.agendaUno));
    expect(ProductoApp.deClave('turnouno'), same(ProductoApp.turnoUno));
    expect(ProductoApp.deClave('otro'), isNull);
    expect(ProductoApp.turnoUno.urlProduccion, 'https://turnouno.mx');
  });

  test('la tienda de cada app es la suya', () {
    expect(
      urlTienda(
        TargetPlatform.android,
        idAndroid: ProductoApp.turnoUno.idAndroid,
      ).toString(),
      'https://play.google.com/store/apps/details?id=com.turnouno.app',
    );
  });

  test('cada petición dice de qué app viene', () async {
    final (c, api) = _contenedor();
    await c
        .read(dioProvider)
        .get<Object>('/api/v1/app/demo/marca')
        .catchError((_) => Response<Object>(requestOptions: RequestOptions()));
    expect(api.peticiones.single.headers['X-App-Producto'], 'agendauno');
    // Una app oficial no se ata a un negocio.
    expect(api.peticiones.single.headers.containsKey('X-App-Negocio'), isFalse);
  });

  testWidgets('un negocio del otro producto: dice qué app descargar', (
    tester,
  ) async {
    final (c, api) = _contenedor();
    await _entrar(tester, c, const LoginScreen(), negocio: 'barberia');

    expect(
      find.text(
        'Este negocio usa TurnoUno. Descarga la app de TurnoUno para entrar.',
      ),
      findsOneWidget,
    );
    final consulta = api.peticiones.last;
    expect(consulta.uri.path, '/api/v1/app/barberia/marca');
    expect(consulta.headers['X-App-Producto'], 'turnouno');
    expect(c.read(sesionProvider), isNull);
  });

  testWidgets('un negocio que no está en ninguna: no lo encontramos', (
    tester,
  ) async {
    final (c, _) = _contenedor();
    // Ni en esta app ni en la de TurnoUno.
    await _entrar(tester, c, const LoginScreen(), negocio: 'nadie');
    expect(
      find.text('No encontramos ese negocio. Revisa la dirección.'),
      findsOneWidget,
    );
  });

  testWidgets('marca blanca: abre directo en su negocio', (tester) async {
    final (c, api) = _contenedor();
    await _entrar(
      tester,
      c,
      const LoginScreen(negocioFijo: 'fluo', nombreApp: 'Fluō Pilates'),
    );

    expect(api.peticiones.single.uri.path, '/api/v1/app/fluo/login');
    expect(c.read(sesionProvider)?.slug, 'fluo');
  });

  testWidgets('marca blanca: su nombre y solo las credenciales', (
    tester,
  ) async {
    await tester.pumpWidget(
      const ProviderScope(
        child: MaterialApp(
          home: LoginScreen(negocioFijo: 'fluo', nombreApp: 'Fluō Pilates'),
        ),
      ),
    );
    expect(find.text('Fluō Pilates'), findsOneWidget);
    expect(find.text('Entra con tu cuenta'), findsOneWidget);
    expect(find.byKey(const Key('direccion-negocio')), findsNothing);
  });
}
