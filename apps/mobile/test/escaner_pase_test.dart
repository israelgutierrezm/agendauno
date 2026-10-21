import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/acceso/data/accesos_repository.dart';
import 'package:agendauno/features/acceso/data/lectura_pase.dart';
import 'package:agendauno/features/acceso/presentation/escaner_pase_screen.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/inicio/data/inicio_repository.dart';
import 'package:agendauno/features/inicio/data/resumen_hoy.dart';
import 'package:agendauno/features/inicio/presentation/equipo_screen.dart';

/// Escanear el pase QR del cliente desde la app del equipo, como la recepción de la
/// web: `POST /accesos` con el código (permiso `checkins.registrar`), quién es y si
/// pasa, o por qué no se registró. La cámara se cambia por una falsa que «lee» el
/// código que se le da. Datos sintéticos, sin red real.

const _pase = 'AU1.01JPERSONA.1790000000.firma';
const _wallet = 'AU2.01JPERSONA.token';

/// API falso: anota cada petición; `/accesos` responde la entrada (o el error que
/// se le pida) y `/sucursales`, dos sedes.
class _ApiAccesos implements HttpClientAdapter {
  _ApiAccesos({this.error});

  /// Si se da, `/accesos` responde 422 con este mensaje (pase inválido).
  final String? error;
  final pedidos = <RequestOptions>[];

  Iterable<RequestOptions> get entradas =>
      pedidos.where((p) => p.path.endsWith('/accesos'));

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    var estado = 200;
    Object cuerpo;
    if (options.path.endsWith('/accesos')) {
      if (error != null) {
        estado = 422;
        cuerpo = {'code': 'ACCESS_PASS_INVALID', 'message': error};
      } else {
        estado = 201;
        cuerpo = {
          'data': {
            'id': 'A1',
            'persona': 'Dana',
            'persona_id': '01JPERSONA',
            'persona_nombre': 'Dana López',
            'metodo': 'qr',
            'resultado': 'permitido',
            'permitido': true,
            'codigo': 'ACCESS_OPEN',
            'registrado_en': '2026-10-01T06:00:00-06:00',
          },
        };
      }
    } else if (options.path.endsWith('/sucursales')) {
      cuerpo = {
        'data': [
          {'id': 's1', 'nombre': 'Centro'},
          {'id': 's2', 'nombre': 'Norte'},
        ],
      };
    } else {
      estado = 404;
      cuerpo = {'message': 'No'};
    }
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

/// Sin red: la petición nunca llega.
class _SinRed implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async => throw DioException.connectionError(
    requestOptions: options,
    reason: 'sin red',
  );

  @override
  void close({bool force = false}) {}
}

const _recepcion = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Recepción',
  rol: 'recepcionista',
  permisos: ['checkins.registrar', 'sucursales.ver'],
  accesoAbierto: true,
);

/// La cámara falsa: un botón por código que «lee» (dos veces seguidas, como la
/// cámara de verdad, que lee el mismo código muchas veces por segundo).
Widget _camaraFalsa(BuildContext context, ValueChanged<String> alLeer) =>
    Column(
      key: const Key('camara'),
      children: [
        for (final (texto, codigo) in [
          ('Leer pase', _pase),
          ('Leer cartera', _wallet),
          ('Leer otro QR', 'https://ejemplo.test'),
        ])
          TextButton(
            onPressed: () {
              alLeer(codigo);
              alLeer(codigo);
            },
            child: Text(texto),
          ),
      ],
    );

Future<void> _montar(
  WidgetTester tester,
  _ApiAccesos api, {
  Sesion sesion = _recepcion,
}) async {
  tester.view.physicalSize = const Size(420, 1200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final dio = Dio()..httpClientAdapter = api;
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(sesion),
        accesosRepositoryProvider.overrideWithValue(
          AccesosRepository(dio, 'demo'),
        ),
        agendaRepositoryProvider.overrideWithValue(
          AgendaRepository(dio, 'demo'),
        ),
      ],
      child: const MaterialApp(home: EscanerPaseScreen(camara: _camaraFalsa)),
    ),
  );
  await tester.pumpAndSettle();
}

class _AgendaVacia extends AgendaController {
  @override
  Future<AgendaDia> build() async =>
      const AgendaDia(sesiones: [], profesionales: []);
}

void main() {
  group('el pase', () {
    test('solo los códigos de la app y de la cartera son pases', () {
      expect(esPaseDeEntrada(_pase), isTrue);
      expect(esPaseDeEntrada('  $_wallet\n'), isTrue);
      expect(esPaseDeEntrada('https://ejemplo.test'), isFalse);
      expect(esPaseDeEntrada('AU1'), isFalse);
      expect(esPaseDeEntrada(''), isFalse);
    });

    test('la entrada: quién es, si pasa y por qué (como la web)', () {
      final permitida = EntradaRegistrada.desdeJson({
        'persona': 'Dana',
        'persona_nombre': 'Dana López',
        'persona_id': 'P1',
        'permitido': true,
        'resultado': 'permitido',
        'codigo': 'ACCESS_BY_BOOKING',
      });
      final denegada = EntradaRegistrada.desdeJson({
        'persona': 'Dana',
        'persona_nombre': ' ',
        'permitido': false,
        'resultado': 'denegado',
        'codigo': 'NO_ACCESS',
      });

      expect(permitida.titulo, 'Entrada permitida');
      expect(permitida.nombre, 'Dana López');
      expect(permitida.motivo, 'Tiene reserva para ahora.');
      expect(denegada.titulo, 'Entrada denegada');
      // Sin nombre completo, el corto.
      expect(denegada.nombre, 'Dana');
      expect(
        denegada.motivo,
        'No tiene reserva ni membresía con acceso libre.',
      );
      expect(
        EntradaRegistrada.desdeJson({
          'permitido': true,
          'codigo': 'ACCESS_OPEN',
        }).motivo,
        'Su membresía le da acceso libre.',
      );
    });
  });

  group('las lecturas de la cámara', () {
    test('una a la vez y la misma, no otra vez enseguida', () {
      var ahora = DateTime(2026, 10, 1, 6);
      final lector = LectorDePase(
        espera: const Duration(seconds: 10),
        reloj: () => ahora,
      );

      expect(lector.tomar(_pase), isTrue);
      // Mientras se atiende, nada más.
      expect(lector.tomar(_pase), isFalse);
      expect(lector.tomar(_wallet), isFalse);
      lector.listo();

      // El mismo código, todavía no; otro, sí.
      ahora = ahora.add(const Duration(seconds: 9));
      expect(lector.tomar(_pase), isFalse);
      expect(lector.tomar(_wallet), isTrue);
      lector.listo();

      ahora = ahora.add(const Duration(seconds: 11));
      expect(lector.tomar(_pase), isTrue);
      lector.listo();
      expect(lector.tomar('  '), isFalse);
    });
  });

  group('las llamadas', () {
    test('registra la entrada con el código y la sede', () async {
      final api = _ApiAccesos();
      final repo = AccesosRepository(Dio()..httpClientAdapter = api, 'demo');

      final conSede = await repo.registrarPase(' $_pase ', sucursalId: 's1');
      await repo.registrarPase(_wallet);

      expect(api.pedidos.first.method, 'POST');
      expect(api.pedidos.first.path, '/api/v1/app/demo/accesos');
      expect(api.pedidos.first.data, {'codigo': _pase, 'sucursal_id': 's1'});
      // Sin sede, en cualquiera: no se manda.
      expect(api.pedidos.last.data, {'codigo': _wallet});
      expect(conSede, isA<EntradaRegistrada>());
      expect((conSede as EntradaRegistrada).nombre, 'Dana López');
    });

    test('lo que no es un pase ni se manda', () async {
      final api = _ApiAccesos();
      final repo = AccesosRepository(Dio()..httpClientAdapter = api, 'demo');

      final r = await repo.registrarPase('https://ejemplo.test');

      expect(api.pedidos, isEmpty);
      expect(
        (r as PaseNoRegistrado).mensaje,
        'Ese código no es un pase de entrada.',
      );
    });

    test('un pase vencido o sin red dice por qué', () async {
      final vencido = await AccesosRepository(
        Dio()
          ..httpClientAdapter = _ApiAccesos(
            error: 'El pase caducó: pide que lo vuelva a mostrar.',
          ),
        'demo',
      ).registrarPase(_pase);
      final sinRed = await AccesosRepository(
        Dio()..httpClientAdapter = _SinRed(),
        'demo',
      ).registrarPase(_pase);

      expect(
        (vencido as PaseNoRegistrado).mensaje,
        'El pase caducó: pide que lo vuelva a mostrar.',
      );
      expect(
        (sinRed as PaseNoRegistrado).mensaje,
        'Sin conexión. Revisa tu internet y vuelve a intentar.',
      );
    });

    test('solo con el permiso y en un negocio que controla la entrada', () {
      const sinPermiso = Sesion(
        slug: 'demo',
        bearer: 't',
        nombre: 'Caja',
        rol: 'caja',
        permisos: ['ordenes.ver'],
        accesoAbierto: true,
      );
      const estudio = Sesion(
        slug: 'demo',
        bearer: 't',
        nombre: 'Dueña',
        rol: 'propietario',
        permisos: ['*'],
      );

      expect(puedeEscanearPase(_recepcion), isTrue);
      expect(puedeEscanearPase(sinPermiso), isFalse);
      expect(puedeEscanearPase(estudio), isFalse);
      expect(puedeEscanearPase(null), isFalse);
    });
  });

  test('la sesión sabe si el negocio controla la entrada', () {
    final sesion = Sesion.desdeJson(
      'demo',
      't',
      {'nombre': 'Recepción', 'rol': 'recepcionista'},
      {
        'perfil_config': {
          'flags': {'acceso_abierto': true},
        },
      },
    );

    expect(sesion.accesoAbierto, isTrue);
    expect(Sesion.desdeAlmacen(sesion.aJson())!.accesoAbierto, isTrue);
    expect(sesion.conUsuario(const {}).accesoAbierto, isTrue);
    expect(
      Sesion.desdeJson('demo', 't', {'rol': 'admin'}, {}).accesoAbierto,
      isFalse,
    );
  });

  group('en la pantalla', () {
    testWidgets('lee el pase, registra la entrada una sola vez y dice quién', (
      tester,
    ) async {
      final api = _ApiAccesos();
      await _montar(tester, api);

      expect(find.text('Apunta la cámara al código QR del pase.'), findsOne);
      await tester.tap(find.text('Leer pase'));
      await tester.pumpAndSettle();

      // La cámara leyó dos veces el mismo código: una sola entrada.
      expect(api.entradas.length, 1);
      expect(api.entradas.single.data, {'codigo': _pase});
      expect(find.text('Entrada permitida'), findsOneWidget);
      expect(find.text('Dana López'), findsOneWidget);
      expect(find.text('Su membresía le da acceso libre.'), findsOneWidget);
      // Con el resultado a la vista, la cámara se apaga.
      expect(find.byKey(const Key('camara')), findsNothing);

      await tester.tap(find.text('Escanear otro pase'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('camara')), findsOneWidget);

      // El mismo código enseguida no se registra otra vez; otro, sí.
      await tester.tap(find.text('Leer pase'));
      await tester.pumpAndSettle();
      expect(api.entradas.length, 1);
      await tester.tap(find.text('Leer cartera'));
      await tester.pumpAndSettle();
      expect(api.entradas.length, 2);
    });

    testWidgets('un pase vencido dice por qué no se registró', (tester) async {
      await _montar(
        tester,
        _ApiAccesos(error: 'El pase caducó: pide que lo vuelva a mostrar.'),
      );

      await tester.tap(find.text('Leer pase'));
      await tester.pumpAndSettle();

      expect(find.text('No se registró la entrada'), findsOneWidget);
      expect(
        find.text('El pase caducó: pide que lo vuelva a mostrar.'),
        findsOneWidget,
      );
    });

    testWidgets('otro QR no es un pase y no llega al servidor', (tester) async {
      final api = _ApiAccesos();
      await _montar(tester, api);

      await tester.tap(find.text('Leer otro QR'));
      await tester.pumpAndSettle();

      expect(api.entradas, isEmpty);
      expect(find.text('Ese código no es un pase de entrada.'), findsOneWidget);
    });

    testWidgets('con varias sedes, se registra en la elegida', (tester) async {
      final api = _ApiAccesos();
      await _montar(tester, api);

      await tester.tap(find.byKey(const Key('sede-entrada')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Norte').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Leer pase'));
      await tester.pumpAndSettle();

      expect(api.entradas.single.data, {'codigo': _pase, 'sucursal_id': 's2'});
    });

    testWidgets('sin ver sucursales, no se elige sede', (tester) async {
      final api = _ApiAccesos();
      await _montar(
        tester,
        api,
        sesion: const Sesion(
          slug: 'demo',
          bearer: 't',
          nombre: 'Recepción',
          rol: 'rol_puerta',
          permisos: ['checkins.registrar'],
          accesoAbierto: true,
        ),
      );

      expect(find.byKey(const Key('sede-entrada')), findsNothing);
      expect(api.pedidos, isEmpty);
    });
  });

  testWidgets('el Inicio del equipo ofrece escanear solo a quien puede', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    Future<void> montar(Sesion sesion) async {
      await tester.pumpWidget(
        ProviderScope(
          key: UniqueKey(),
          overrides: [
            sesionInicialProvider.overrideWithValue(sesion),
            resumenHoyProvider.overrideWith(
              (ref) async =>
                  ResumenHoy.desdeJson({'fecha': 'x', 'agenda': null}),
            ),
            climaNegocioProvider.overrideWith((ref) async => null),
            agendaProvider.overrideWith(_AgendaVacia.new),
          ],
          child: const MaterialApp(home: EquipoScreen()),
        ),
      );
      await tester.pumpAndSettle();
    }

    await montar(_recepcion);
    expect(find.text('Escanear pase'), findsOneWidget);

    // Un estudio (entrar es tomar la clase): no hay entrada que registrar.
    await montar(
      const Sesion(
        slug: 'demo',
        bearer: 't',
        nombre: 'Recepción',
        rol: 'recepcionista',
        permisos: ['checkins.registrar'],
      ),
    );
    expect(find.text('Escanear pase'), findsNothing);
  });
}
