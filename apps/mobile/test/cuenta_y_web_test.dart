import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/auth/presentation/abrir_en_la_web.dart';
import 'package:agendauno/features/perfil/presentation/calendarios_externos_sheet.dart';
import 'package:agendauno/features/perfil/presentation/eliminar_cuenta_sheet.dart';
import 'package:agendauno/features/perfil/presentation/perfil_screen.dart';

/// Eliminar la cuenta desde la app (cualquier rol: lo piden Apple y Google), con su
/// plazo y su efecto dichos antes y confirmada con la contraseña; y «Abrir en la
/// web» con un enlace de un solo uso para lo que se hace allá (OpenPay, conectar
/// Google u Outlook). Sin red real.

class _ApiCuenta implements HttpClientAdapter {
  _ApiCuenta({this.estado = 200, this.cuerpo = const {}});

  final int estado;
  final Map<String, dynamic> cuerpo;
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    final Object respuesta = switch (options.path) {
      final p when p.endsWith('/yo/calendarios') => {
        'data': {
          'disponible': true,
          'proveedores': [
            {
              'proveedor': 'google',
              'configurado': true,
              'conexion': {'cuenta': 'luis@gmail.com', 'estado': 'activo'},
            },
            {'proveedor': 'microsoft', 'configurado': true, 'conexion': null},
          ],
        },
      },
      final p when p.endsWith('/yo/enlace-web') => {
        'data': {
          'url':
              cuerpo['url'] ??
              'https://agendauno.mx/entrar?estudio=demo&enlace=abc',
          'vence_en': '2026-10-25T12:02:00Z',
        },
      },
      _ => cuerpo,
    };
    return ResponseBody.fromString(
      jsonEncode(respuesta),
      options.path.endsWith('/eliminar-cuenta') ? estado : 201,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

const _cliente = Sesion(
  slug: 'demo',
  bearer: 'tok',
  nombre: 'Ana',
  rol: 'miembro',
  estudioNombre: 'Estudio Luna',
  tieneFicha: true,
);

ProviderContainer _contenedor(
  _ApiCuenta api, {
  Sesion sesion = _cliente,
  Future<bool> Function(Uri)? abrir,
}) {
  FlutterSecureStorage.setMockInitialValues({});
  final c = ProviderContainer(
    overrides: [
      sesionInicialProvider.overrideWithValue(sesion),
      dioProvider.overrideWithValue(Dio()..httpClientAdapter = api),
      if (abrir != null) abrirFueraProvider.overrideWithValue(abrir),
    ],
  );
  addTearDown(c.dispose);
  return c;
}

Future<void> _montar(
  WidgetTester tester,
  ProviderContainer c,
  Widget hoja,
) async {
  tester.view.physicalSize = const Size(420, 1400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: c,
      child: MaterialApp(home: Scaffold(body: hoja)),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  test(
    'eliminar la cuenta cierra la sesión y el acceso dice qué sigue',
    () async {
      final api = _ApiCuenta(
        cuerpo: {
          'data': {
            'estado': 'solicitada',
            'mensaje': 'Tu cuenta quedó eliminada.',
          },
        },
      );
      final c = _contenedor(api);

      await c
          .read(sesionProvider.notifier)
          .eliminarCuenta('secreta', motivo: '  Me mudé  ');

      final pedido = api.pedidos.single;
      expect(pedido.method, 'POST');
      expect(pedido.path, '/api/v1/app/demo/yo/eliminar-cuenta');
      expect(pedido.data, {'password': 'secreta', 'motivo': 'Me mudé'});
      expect(c.read(sesionProvider), isNull);
      final fin = c.read(sesionTerminadaProvider)!;
      expect(fin.motivo, MotivoFin.cuentaEliminada);
      expect(fin.mensaje, 'Tu cuenta quedó eliminada.');
    },
  );

  test('si el servidor se niega (el único dueño), la sesión sigue', () async {
    final api = _ApiCuenta(
      estado: 422,
      cuerpo: {
        'message':
            'Eres el dueño del negocio: para eliminar tu cuenta, primero da de baja el negocio desde Mi suscripción.',
      },
    );
    final c = _contenedor(api);

    await expectLater(
      c.read(sesionProvider.notifier).eliminarCuenta('secreta'),
      throwsA(isA<DioException>()),
    );
    expect(c.read(sesionProvider)?.slug, 'demo');
    expect(api.pedidos.single.data, {'password': 'secreta'});
  });

  testWidgets('dice el plazo y el efecto, y confirma con la contraseña', (
    tester,
  ) async {
    final api = _ApiCuenta(
      estado: 422,
      cuerpo: {
        'message': 'Los datos proporcionados no son válidos.',
        'meta': {
          'errors': {
            'password': ['La contraseña no coincide.'],
          },
        },
      },
    );
    final c = _contenedor(api);
    await _montar(tester, c, const EliminarCuentaSheet());

    expect(find.textContaining('Se elimina en el acto'), findsOneWidget);
    expect(find.textContaining('en el plazo que marca la ley'), findsOneWidget);
    expect(find.textContaining('Estudio Luna'), findsWidgets);

    // Sin contraseña no se manda nada.
    await tester.tap(find.byKey(const Key('confirmar-eliminar')));
    await tester.pumpAndSettle();
    expect(find.text('Escribe tu contraseña para confirmar.'), findsOneWidget);
    expect(api.pedidos, isEmpty);

    await tester.enterText(
      find.byKey(const Key('contrasena-eliminar')),
      'mala',
    );
    await tester.tap(find.byKey(const Key('confirmar-eliminar')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sí, eliminarla'));
    await tester.pumpAndSettle();

    expect(api.pedidos, hasLength(1));
    expect(find.text('La contraseña no coincide.'), findsOneWidget);
    expect(c.read(sesionProvider), isNotNull);
  });

  testWidgets('quien entra solo con Google primero crea su contraseña', (
    tester,
  ) async {
    final c = _contenedor(
      _ApiCuenta(),
      sesion: const Sesion(
        slug: 'demo',
        bearer: 'tok',
        nombre: 'Luis',
        rol: 'instructor',
        tieneContrasena: false,
      ),
    );
    await _montar(tester, c, const EliminarCuentaSheet());

    expect(find.byKey(const Key('sin-contrasena')), findsOneWidget);
    expect(find.textContaining('¿Olvidaste tu contraseña?'), findsOneWidget);
    expect(find.byKey(const Key('confirmar-eliminar')), findsNothing);
    // Sin ficha de cliente no se habla de borrar sus datos de cliente.
    expect(find.textContaining('en el plazo que marca la ley'), findsNothing);
  });

  testWidgets('Mi perfil: eliminar la cuenta con cualquier rol', (
    tester,
  ) async {
    for (final sesion in const [
      _cliente,
      Sesion(
        slug: 'demo',
        bearer: 'tok',
        nombre: 'Luis',
        rol: 'instructor',
        roles: ['instructor'],
      ),
      Sesion(
        slug: 'demo',
        bearer: 'tok',
        nombre: 'Rita',
        rol: 'recepcionista',
        roles: ['recepcionista'],
      ),
    ]) {
      final c = _contenedor(_ApiCuenta(), sesion: sesion);
      await _montar(tester, c, const PerfilScreen());
      await tester.scrollUntilVisible(
        find.byKey(const Key('eliminar-cuenta')),
        300,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.byKey(const Key('eliminar-cuenta')), findsOneWidget);
      // Sincronizar Google u Outlook: solo quien atiende.
      expect(
        find.byKey(const Key('calendarios-externos')),
        sesion.rol == 'instructor' ? findsOneWidget : findsNothing,
      );
    }
  });

  test('el enlace de un solo uso solo abre la web (http o https)', () async {
    final api = _ApiCuenta();
    final c = _contenedor(api);

    final url = await c
        .read(sesionProvider.notifier)
        .enlaceWeb(DestinosWeb.pagoAutomatico);

    expect(url?.host, 'agendauno.mx');
    expect(api.pedidos.single.path, '/api/v1/app/demo/yo/enlace-web');
    expect(api.pedidos.single.data, {'destino': '/mi-cuenta/pagos#pago-automatico'});

    final otro = _contenedor(
      _ApiCuenta(cuerpo: {'url': 'javascript:alert(1)'}),
    );
    expect(
      await otro.read(sesionProvider.notifier).enlaceWeb('/mi-perfil'),
      isNull,
    );
  });

  testWidgets('Google u Outlook: cómo está y se conecta en la web', (
    tester,
  ) async {
    final api = _ApiCuenta();
    final abiertas = <Uri>[];
    final c = _contenedor(
      api,
      sesion: const Sesion(
        slug: 'demo',
        bearer: 'tok',
        nombre: 'Luis',
        rol: 'instructor',
        roles: ['instructor'],
      ),
      abrir: (url) async {
        abiertas.add(url);
        return true;
      },
    );
    await _montar(tester, c, const CalendariosExternosSheet());

    expect(atiende(c.read(sesionProvider)!), isTrue);
    expect(find.text('Conectado · luis@gmail.com'), findsOneWidget);
    expect(find.text('Sin conectar'), findsOneWidget);
    await tester.tap(find.byKey(const Key('calendarios-en-la-web')));
    await tester.pumpAndSettle();

    expect(abiertas.single.queryParameters['enlace'], 'abc');
    expect(api.pedidos.last.data, {
      'destino': '/mi-perfil#mp-preferencias',
    });
  });
}
