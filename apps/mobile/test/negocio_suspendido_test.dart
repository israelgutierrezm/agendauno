import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/network/auth_token.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';
import 'package:agendauno/features/auth/presentation/negocio_suspendido_screen.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_screen.dart';
import 'package:agendauno/main.dart';

/// El estado del negocio llega con /yo (`estudio.estado`). Suspendido por renta
/// (ADR 0073) solo quedan abiertos /yo y el acceso: la app lo dice en lugar de
/// mostrar pestañas que no cargarían. Si /yo responde 404, el negocio ya no está
/// (cancelado o dado de baja) y la sesión se cierra con su aviso. Al volver a la
/// app, /yo refresca toda la sesión. Datos sintéticos.

const _ana = Sesion(
  slug: 'demo',
  bearer: 'token-ana',
  nombre: 'Ana',
  rol: 'miembro',
);

/// API falso: /yo responde con el estado del negocio, los permisos y el plan
/// dados (o 404 / sin red); lo demás, 404.
class _ApiYo implements HttpClientAdapter {
  String estado = 'active';
  String modalidad = 'clases';
  String rol = 'miembro';
  bool variosRoles = false;
  List<String> permisos = const [];
  List<String> sin = const [];
  bool negocioBorrado = false;
  bool sinRed = false;
  int? errorServidor;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    if (sinRed) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'sin red',
      );
    }
    final yo = options.path.endsWith('/yo');
    final (
      int codigo,
      Object cuerpo,
    ) = options.path.endsWith('/login') && !negocioBorrado
        ? (
            422,
            {
              'code': 'VALIDATION_FAILED',
              'message': 'Los datos proporcionados no son válidos.',
              'meta': {
                'errors': {
                  'email': [
                    'Este negocio está suspendido por ahora. Vuelve a '
                        'intentarlo más tarde.',
                  ],
                },
              },
            },
          )
        : !yo || negocioBorrado
        ? (404, {'code': 'NOT_FOUND', 'message': 'Estudio no encontrado.'})
        : errorServidor != null
        ? (errorServidor!, {'message': 'Ocurrió un error inesperado.'})
        : (
            200,
            {
              'data': {
                'usuario': {
                  'nombre': 'Ana',
                  'rol': rol,
                  'permisos': permisos,
                  'roles_disponibles': [
                    {'clave': 'miembro', 'faceta': 'miembro'},
                    if (variosRoles)
                      {'clave': 'propietario', 'faceta': 'equipo'},
                  ],
                },
                'estudio': {
                  'nombre': 'Estudio Demo',
                  'estado': estado,
                  'modalidad': modalidad,
                  'perfil_config': {
                    'terminologia': {'sesion': 'Turno'},
                  },
                  'plan': {'nivel': 'individual', 'sin': sin},
                },
                'app': {'version_minima': '0.0.0'},
              },
            },
          );
    return ResponseBody.fromString(
      jsonEncode(cuerpo),
      codigo,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<(ProviderContainer, _ApiYo)> _contenedor({Sesion sesion = _ana}) async {
  FlutterSecureStorage.setMockInitialValues({
    'agendauno.sesion': jsonEncode(sesion.aJson()),
  });
  final c = ProviderContainer(
    overrides: [
      almacenSesionProvider.overrideWithValue(AlmacenSesion()),
      tokenInicialProvider.overrideWithValue(sesion.bearer),
      sesionInicialProvider.overrideWithValue(sesion),
    ],
    // Lo que falla (su cuenta, con el API falso) se muestra sin reintentar.
    retry: (_, _) => null,
  );
  final api = _ApiYo();
  c.read(dioProvider).httpClientAdapter = api;
  return (c, api);
}

Future<void> _montarApp(WidgetTester tester, ProviderContainer c) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    UncontrolledProviderScope(container: c, child: const AgendaUnoApp()),
  );
  await tester.pumpAndSettle();
}

void main() {
  test('la sesión toma el estado del negocio y lo guarda', () {
    final s = Sesion.desdeJson(
      'demo',
      't',
      {'nombre': 'Ana', 'rol': 'miembro'},
      {'estado': 'suspended'},
    );

    expect(s.negocioSuspendido, isTrue);
    expect(Sesion.desdeAlmacen(s.aJson())!.negocioSuspendido, isTrue);
    expect(s.conUsuario({'nombre': 'Ana María'}).negocioSuspendido, isTrue);
    expect(
      Sesion.desdeJson(
        'demo',
        't',
        {'nombre': 'Ana'},
        {'estado': 'active'},
      ).negocioSuspendido,
      isFalse,
    );
    // Un API anterior no lo manda: no se supone suspendido.
    expect(
      Sesion.desdeJson('demo', 't', {'nombre': 'Ana'}).negocioSuspendido,
      isFalse,
    );
  });

  testWidgets('suspendido: quien paga la renta ve cómo reactivarlo', (
    tester,
  ) async {
    final (c, api) = await _contenedor();
    addTearDown(c.dispose);
    api
      ..estado = 'suspended'
      ..rol = 'propietario'
      ..permisos = const ['*'];

    await _montarApp(tester, c);

    expect(find.byType(NegocioSuspendidoScreen), findsOneWidget);
    expect(
      find.text(
        'Tu negocio está suspendido por falta de pago. Paga tu suscripción en '
        'agendauno.mx para reactivarlo.',
      ),
      findsOneWidget,
    );
    expect(find.byType(CuentaScreen), findsNothing);
  });

  testWidgets('suspendido: los demás ven que no está disponible; al pagarse, '
      'volver a revisar entra', (tester) async {
    final (c, api) = await _contenedor();
    addTearDown(c.dispose);
    api.estado = 'suspended';

    await _montarApp(tester, c);

    expect(
      find.text('Este negocio no está disponible por ahora.'),
      findsOneWidget,
    );
    expect(find.textContaining('falta de pago'), findsNothing);
    expect(find.text('Cerrar sesión'), findsOneWidget);

    api.estado = 'active';
    await tester.tap(find.text('Volver a revisar'));
    await tester.pumpAndSettle();

    expect(find.byType(NegocioSuspendidoScreen), findsNothing);
    expect(find.byType(CuentaScreen), findsOneWidget);
  });

  testWidgets('suspendido: cerrar sesión vuelve al login', (tester) async {
    final (c, api) = await _contenedor();
    addTearDown(c.dispose);
    api.estado = 'suspended';
    await _montarApp(tester, c);

    await tester.tap(find.text('Cerrar sesión'));
    await tester.pumpAndSettle();

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(c.read(sesionProvider), isNull);
  });

  testWidgets('/yo 404: el negocio ya no está; se cierra la sesión con su '
      'aviso', (tester) async {
    final (c, api) = await _contenedor();
    addTearDown(c.dispose);
    api.negocioBorrado = true;

    await _montarApp(tester, c);

    expect(c.read(sesionProvider), isNull);
    expect(c.read(authTokenProvider), isNull);
    expect(c.read(sesionTerminadaProvider)?.motivo, MotivoFin.negocio);
    expect(await AlmacenSesion().leer(), isNull);
    expect(find.byType(LoginScreen), findsOneWidget);
    expect(
      find.textContaining('Este negocio ya no está disponible'),
      findsOneWidget,
    );
  });

  testWidgets('el login dice la razón del servidor y, con 404, que el negocio '
      'no existe', (tester) async {
    FlutterSecureStorage.setMockInitialValues({});
    final c = ProviderContainer(
      overrides: [almacenSesionProvider.overrideWithValue(AlmacenSesion())],
    );
    addTearDown(c.dispose);
    final api = _ApiYo();
    c.read(dioProvider).httpClientAdapter = api;
    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: c,
        child: const MaterialApp(home: LoginScreen()),
      ),
    );

    Future<void> entrar() async {
      await tester.enterText(find.byType(TextField).at(0), 'demo');
      await tester.enterText(find.byType(TextField).at(1), 'ana@correo.mx');
      await tester.enterText(find.byType(TextField).at(2), 'secreta');
      await tester.tap(find.text('Entrar'));
      await tester.pumpAndSettle();
    }

    // Un 422: la razón de meta.errors, no el mensaje fijo.
    await entrar();
    expect(find.textContaining('suspendido por ahora'), findsOneWidget);
    expect(find.text('Los datos proporcionados no son válidos.'), findsNothing);

    api.negocioBorrado = true;
    await entrar();
    expect(
      find.text('No encontramos ese negocio. Revisa la dirección.'),
      findsOneWidget,
    );
  });

  test('al volver a la app, /yo refresca permisos, plan, modalidad y '
      'terminología', () async {
    final (c, api) = await _contenedor();
    addTearDown(c.dispose);
    api
      ..modalidad = 'citas'
      ..permisos = const ['reservas.ver']
      ..sin = const ['venta_en_linea'];

    await c.read(sesionProvider.notifier).revisar();

    final s = c.read(sesionProvider)!;
    expect(s.permisos, ['reservas.ver']);
    expect(s.esCitas, isTrue);
    expect(s.terminologia.sesion, 'Turno');
    expect(s.nivelPlan, 'individual');
    expect(s.tieneFuncion('venta_en_linea'), isFalse);
    expect(s.estadoNegocio, 'active');
    // Se guarda para la siguiente apertura.
    final guardada = Sesion.desdeAlmacen((await AlmacenSesion().leer())!)!;
    expect(guardada.tieneFuncion('venta_en_linea'), isFalse);
  });

  test('sin red o con un error del servidor, la sesión se conserva', () async {
    final (c, api) = await _contenedor();
    addTearDown(c.dispose);

    api.sinRed = true;
    await c.read(sesionProvider.notifier).revisar();
    expect(c.read(sesionProvider), isNotNull);

    api
      ..sinRed = false
      ..errorServidor = 500;
    await c.read(sesionProvider.notifier).revisar();
    expect(c.read(sesionProvider), isNotNull);
    expect(c.read(sesionTerminadaProvider), isNull);
  });

  test(
    'recién entró con varios roles: revisar no se salta la elección',
    () async {
      final (c, api) = await _contenedor(
        sesion: const Sesion(
          slug: 'demo',
          bearer: 'token-ana',
          nombre: 'Ana',
          rol: 'miembro',
          eligiendoRol: true,
        ),
      );
      addTearDown(c.dispose);
      api.variosRoles = true;

      await c.read(sesionProvider.notifier).revisar();

      expect(c.read(sesionProvider)!.eligiendoRol, isTrue);
      expect(c.read(sesionProvider)!.tieneVariosRoles, isTrue);
    },
  );
}
