import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/config/app_config.dart';
import 'package:agendauno/core/config/producto_app.dart';
import 'package:agendauno/core/network/auth_token.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/core/version/version_app.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/auth/presentation/actualizar_app_screen.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_screen.dart';
import 'package:agendauno/main.dart';

/// Versión mínima de la app (ADR 0104): /yo dice la más antigua que el servidor
/// acepta; una app más vieja pide actualizarse en lugar de leer contratos que ya
/// no entiende. Datos sintéticos.

const _ana = Sesion(
  slug: 'demo',
  bearer: 'token-ana',
  nombre: 'Ana',
  rol: 'miembro',
);

/// API falso: /yo responde con la versión mínima dada; lo demás, 404.
class _ApiYo implements HttpClientAdapter {
  _ApiYo(this.minima);

  String minima;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final yo = options.path.endsWith('/yo');
    return ResponseBody.fromString(
      jsonEncode(
        yo
            ? {
                'data': {
                  'usuario': {'nombre': 'Ana', 'rol': 'miembro'},
                  'estudio': {
                    'nombre': 'Estudio Demo',
                    'modalidad': 'clases',
                    'capacidades': {'clases': true, 'citas': false},
                  },
                  'app': {'version_minima': minima},
                },
              }
            : {'message': 'No'},
      ),
      yo ? 200 : 404,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<(ProviderContainer, _ApiYo)> _contenedor(
  String minima, {
  Sesion sesion = _ana,
}) async {
  FlutterSecureStorage.setMockInitialValues({
    'agendauno.sesion': jsonEncode(sesion.aJson()),
  });
  final c = ProviderContainer(
    overrides: [
      almacenSesionProvider.overrideWithValue(AlmacenSesion()),
      tokenInicialProvider.overrideWithValue(sesion.bearer),
      sesionInicialProvider.overrideWithValue(sesion),
    ],
  );
  final api = _ApiYo(minima);
  c.read(dioProvider).httpClientAdapter = api;
  return (c, api);
}

void main() {
  test('la versión de la app es la de pubspec.yaml', () {
    final pubspec = File('pubspec.yaml').readAsStringSync();
    final version = RegExp(
      r'^version:\s*([^\s+]+)',
      multiLine: true,
    ).firstMatch(pubspec)!.group(1);

    expect(versionApp, version);
  });

  test('compara número por número y una mínima ilegible no bloquea', () {
    expect(esMasVieja('1.0.0', '1.0.1'), isTrue);
    expect(esMasVieja('1.9.0', '1.10.0'), isTrue);
    expect(esMasVieja('1.10.0', '1.9.9'), isFalse);
    expect(esMasVieja('1.0.0', '1.0.0'), isFalse);
    expect(esMasVieja('1.0.0', '1.0'), isFalse);
    expect(esMasVieja('1.0', '1.0.1'), isTrue);
    expect(esMasVieja('2.0.0', '1.4.0'), isFalse);
    // Lo que va después de «+» o «-» no cuenta.
    expect(esMasVieja('1.2.0+40', '1.2.0'), isFalse);
    expect(esMasVieja('1.2.0', '1.2.0-beta'), isFalse);
    expect(esMasVieja('1.0.0', versionMinimaDesconocida), isFalse);
    expect(esMasVieja('1.0.0', 'mañana'), isFalse);
    expect(esMasVieja('1.0.0', ''), isFalse);
  });

  test('la sesión toma la versión mínima de /yo y la guarda', () {
    final s = Sesion.desdeJson(
      'demo',
      't',
      {'nombre': 'Ana', 'rol': 'miembro'},
      {'nombre': 'Estudio Demo'},
      {'version_minima': '99.0.0'},
    );

    expect(s.versionMinima, '99.0.0');
    expect(s.debeActualizar, isTrue);
    expect(Sesion.desdeAlmacen(s.aJson())!.debeActualizar, isTrue);
    // Cambiar datos del usuario no la pierde.
    expect(s.conUsuario({'nombre': 'Ana María'}).versionMinima, '99.0.0');

    // El login no la trae: no se exige ninguna hasta que /yo la diga.
    final login = Sesion.desdeJson('demo', 't', {
      'nombre': 'Ana',
      'rol': 'miembro',
    });
    expect(login.versionMinima, versionMinimaDesconocida);
    expect(login.debeActualizar, isFalse);
  });

  test('al revisar la sesión, /yo actualiza la versión mínima', () async {
    final (c, api) = await _contenedor('0.0.0');
    addTearDown(c.dispose);

    await c.read(sesionProvider.notifier).revisar();
    expect(c.read(sesionProvider)!.debeActualizar, isFalse);

    api.minima = '99.0.0';
    await c.read(sesionProvider.notifier).revisar();
    expect(c.read(sesionProvider)!.versionMinima, '99.0.0');
    expect(c.read(sesionProvider)!.debeActualizar, isTrue);
    // Se guarda: al volver a abrir la app sigue pidiendo actualizar.
    final guardada = await AlmacenSesion().leer();
    expect(Sesion.desdeAlmacen(guardada!)!.debeActualizar, isTrue);

    // Si la bajan, se puede seguir.
    api.minima = versionApp;
    await c.read(sesionProvider.notifier).refrescar();
    expect(c.read(sesionProvider)!.debeActualizar, isFalse);
  });

  testWidgets('con una versión más vieja que la mínima, la app pide '
      'actualizarse y no deja seguir', (tester) async {
    final (c, _) = await _contenedor('99.0.0');
    addTearDown(c.dispose);
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      UncontrolledProviderScope(container: c, child: const AgendaUnoApp()),
    );
    await tester.pumpAndSettle();

    expect(find.byType(ActualizarAppScreen), findsOneWidget);
    expect(find.text('Actualiza AgendaUno para continuar'), findsOneWidget);
    expect(find.textContaining('se necesita la 99.0.0'), findsOneWidget);
    expect(find.byType(CuentaScreen), findsNothing);
    // En Android (el de las pruebas) lleva a su página en Google Play.
    expect(find.byKey(const Key('abrir-tienda')), findsOneWidget);
  });

  test('la tienda: Google Play en Android; el App Store solo con su id', () {
    expect(
      urlTienda(TargetPlatform.android).toString(),
      'https://play.google.com/store/apps/details?id=com.agendauno.app',
    );
    expect(urlTienda(TargetPlatform.iOS, appStoreId: ''), isNull);
    expect(
      urlTienda(TargetPlatform.iOS, appStoreId: '1234567890').toString(),
      'https://apps.apple.com/app/id1234567890',
    );
    expect(urlTienda(TargetPlatform.macOS), isNull);
  });

  test('los ids de Google Play son los applicationId de Android', () {
    final gradle = File('android/app/build.gradle.kts').readAsStringSync();
    // Uno por app oficial (ADR 0111), cada uno en el applicationId de su sabor.
    for (final producto in ProductoApp.todos) {
      expect(
        RegExp(
          'applicationId = .*"${RegExp.escape(producto.idAndroid)}"',
        ).hasMatch(gradle),
        isTrue,
        reason: producto.nombre,
      );
    }
    expect(AppConfig.idAndroid, ProductoApp.agendaUno.idAndroid);
  });

  testWidgets('con una versión aceptada entra como siempre', (tester) async {
    final (c, _) = await _contenedor(versionApp);
    addTearDown(c.dispose);
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      UncontrolledProviderScope(container: c, child: const AgendaUnoApp()),
    );
    await tester.pumpAndSettle();

    expect(find.byType(ActualizarAppScreen), findsNothing);
    expect(find.byType(CuentaScreen), findsOneWidget);
  });
}
