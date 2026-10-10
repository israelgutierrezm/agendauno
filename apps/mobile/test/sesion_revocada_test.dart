import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/network/auth_token.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/network/sesion_revocada.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_screen.dart';
import 'package:agendauno/main.dart';

/// Token revocado con la app abierta (se cerró sesión en la web u otro teléfono,
/// se cambió la contraseña o se dio de baja la cuenta): el primer 401 de una ruta
/// del negocio cierra la sesión en el teléfono, sin llamar a /logout, y el login
/// dice que la sesión terminó con la dirección del negocio ya escrita. Datos
/// sintéticos.

const _ana = Sesion(
  slug: 'demo',
  bearer: 'token-ana',
  nombre: 'Ana',
  rol: 'miembro',
);

/// API falso: /yo responde según [revocado]; lo demás del negocio, 401 si está
/// revocado y 404 si no. Guarda lo que se le pidió.
class _ApiSesion implements HttpClientAdapter {
  bool revocado = false;
  bool sinRed = false;
  final List<RequestOptions> peticiones = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    if (sinRed) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'sin red',
      );
    }
    final (int estado, Object cuerpo) = options.path.endsWith('/login')
        ? (
            200,
            {
              'data': {
                'token': 'token-nuevo',
                'usuario': {'nombre': 'Ana', 'rol': 'miembro'},
              },
            },
          )
        : revocado
        ? (401, {'message': 'No autenticado.'})
        : options.path.endsWith('/yo')
        ? (
            200,
            {
              'data': {
                'usuario': {'nombre': 'Ana', 'rol': 'miembro'},
              },
            },
          )
        : (404, {'message': 'No'});
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

DioException _error(String ruta, {int estado = 401, String? token}) {
  final opciones = RequestOptions(
    baseUrl: 'https://agendauno.mx',
    path: ruta,
    headers: {'Authorization': ?(token == null ? null : 'Bearer $token')},
  );
  return DioException(
    requestOptions: opciones,
    response: Response(requestOptions: opciones, statusCode: estado),
  );
}

/// Contenedor con la sesión de Ana guardada y la red real (con su interceptor)
/// sobre el API falso.
Future<(ProviderContainer, _ApiSesion)> _contenedorRevocada() async {
  FlutterSecureStorage.setMockInitialValues({
    'agendauno.sesion': jsonEncode(_ana.aJson()),
  });
  final c = ProviderContainer(
    overrides: [
      almacenSesionProvider.overrideWithValue(AlmacenSesion()),
      tokenInicialProvider.overrideWithValue(_ana.bearer),
      sesionInicialProvider.overrideWithValue(_ana),
    ],
  );
  final api = _ApiSesion();
  c.read(dioProvider).httpClientAdapter = api;
  return (c, api);
}

void main() {
  group('¿el 401 dice que la sesión terminó?', () {
    test('sí: una ruta del negocio pedida con el token vigente', () {
      expect(
        esSesionRevocada(
          _error('/api/v1/app/demo/mi/perfil', token: 't1'),
          't1',
        ),
        isTrue,
      );
    });

    test('no: entrar, entrar con Google, salir o recuperar el acceso', () {
      for (final ruta in [
        'login',
        'auth/google',
        'logout',
        'recuperar-contrasena',
      ]) {
        expect(
          esSesionRevocada(_error('/api/v1/app/demo/$ruta', token: 't1'), 't1'),
          isFalse,
          reason: ruta,
        );
      }
    });

    test('no: la respuesta tardía de otra sesión, sin token o sin sesión', () {
      final ruta = '/api/v1/app/demo/yo';
      expect(esSesionRevocada(_error(ruta, token: 'viejo'), 'nuevo'), isFalse);
      expect(esSesionRevocada(_error(ruta), 't1'), isFalse);
      expect(esSesionRevocada(_error(ruta, token: 't1'), null), isFalse);
    });

    test('no: otro código u otra ruta fuera del negocio', () {
      expect(
        esSesionRevocada(
          _error('/api/v1/app/demo/yo', estado: 403, token: 't1'),
          't1',
        ),
        isFalse,
      );
      expect(
        esSesionRevocada(_error('/api/v1/errores', token: 't1'), 't1'),
        isFalse,
      );
    });
  });

  test(
    'un 401 de cualquier pantalla cierra la sesión sin llamar a /logout',
    () async {
      final (c, api) = await _contenedorRevocada();
      addTearDown(c.dispose);
      expect(c.read(sesionProvider), isNotNull);

      api.revocado = true;
      await expectLater(
        c.read(dioProvider).get<Object>('/api/v1/app/demo/mi/perfil'),
        throwsA(isA<DioException>()),
      );

      expect(c.read(sesionProvider), isNull);
      expect(c.read(authTokenProvider), isNull);
      expect(c.read(sesionTerminadaProvider)?.slug, 'demo');
      expect(await AlmacenSesion().leer(), isNull);
      expect(api.peticiones.where((p) => p.path.endsWith('/logout')), isEmpty);
    },
  );

  test('al volver a la app se revisa la sesión: vigente o sin red, sigue; '
      'revocada, termina', () async {
    final (c, api) = await _contenedorRevocada();
    addTearDown(c.dispose);

    await c.read(sesionProvider.notifier).revisar();
    expect(c.read(sesionProvider), isNotNull);
    expect(api.peticiones.single.path, '/api/v1/app/demo/yo');

    api.sinRed = true;
    await c.read(sesionProvider.notifier).revisar();
    expect(c.read(sesionProvider), isNotNull);
    expect(c.read(sesionTerminadaProvider), isNull);

    api.sinRed = false;
    api.revocado = true;
    await c.read(sesionProvider.notifier).revisar();
    expect(c.read(sesionProvider), isNull);
    expect(c.read(sesionTerminadaProvider)?.slug, 'demo');
  });

  test('al volver a entrar se olvida el aviso', () async {
    final (c, api) = await _contenedorRevocada();
    addTearDown(c.dispose);
    api.revocado = true;
    await c.read(sesionProvider.notifier).revisar();
    expect(c.read(sesionTerminadaProvider)?.slug, 'demo');

    await c
        .read(sesionProvider.notifier)
        .iniciar('demo', 'ana@correo.mx', 'secreta');

    expect(c.read(sesionTerminadaProvider), isNull);
    expect(c.read(sesionProvider)?.bearer, 'token-nuevo');
    expect(c.read(authTokenProvider), 'token-nuevo');
  });

  testWidgets('con la app abierta, vuelve al login con el aviso y quita lo que '
      'estaba encima', (tester) async {
    final (c, api) = await _contenedorRevocada();
    addTearDown(c.dispose);
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      UncontrolledProviderScope(container: c, child: const AgendaUnoApp()),
    );
    await tester.pumpAndSettle();
    expect(find.byType(CuentaScreen), findsOneWidget);

    // Una pantalla de detalle abierta encima.
    Navigator.of(tester.element(find.byType(CuentaScreen))).push(
      MaterialPageRoute<void>(
        builder: (_) => const Scaffold(body: Text('Detalle de la reserva')),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Detalle de la reserva'), findsOneWidget);

    // Cerró sesión en la web: lo siguiente que pide la app recibe 401.
    api.revocado = true;
    unawaited(c.read(sesionProvider.notifier).revisar());
    await tester.pumpAndSettle();

    expect(find.text('Detalle de la reserva'), findsNothing);
    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.byKey(const Key('sesion-terminada')), findsOneWidget);
    expect(
      find.widgetWithText(TextField, 'demo'),
      findsOneWidget,
      reason: 'la dirección del negocio ya escrita',
    );
    expect(api.peticiones.where((p) => p.path.endsWith('/logout')), isEmpty);
  });

  testWidgets('sin aviso, el login no lo muestra', (tester) async {
    await tester.pumpWidget(
      const ProviderScope(child: MaterialApp(home: LoginScreen())),
    );

    expect(find.byKey(const Key('sesion-terminada')), findsNothing);
  });
}
