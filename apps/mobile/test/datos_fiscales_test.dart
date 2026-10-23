import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/perfil/data/catalogos_sat.dart';
import 'package:agendauno/features/perfil/data/datos_fiscales.dart';
import 'package:agendauno/features/perfil/presentation/perfil_screen.dart';

/// Mis datos para facturar en Mi perfil del cliente: `GET/PUT/DELETE
/// /mi/datos-fiscales`, los errores del servidor junto a cada campo y la factura
/// automática solo si el negocio la ofrece. API falso; datos sintéticos.

const _guardados = {
  'rfc': 'GOMA800101AB1',
  'nombre': 'ANA GÓMEZ MARTÍNEZ',
  'codigo_postal': '06700',
  'regimen_fiscal': '612',
  'uso_cfdi': 'D01',
  'email': 'ana@example.test',
  'factura_automatica': true,
};

/// API falso: responde lo guardado (o nada), y al guardar, lo mandado o el 422
/// que se le pida.
class _ApiFiscal implements HttpClientAdapter {
  _ApiFiscal({this.datos, this.automatica = false, this.errores});

  Map<String, dynamic>? datos;
  final bool automatica;

  /// Si se da, el PUT responde 422 con estos errores por campo.
  final Map<String, List<String>>? errores;
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    var codigo = 200;
    Object? cuerpo;
    switch (options.method) {
      case 'PUT' when errores != null:
        codigo = 422;
        cuerpo = {
          'code': 'VALIDATION_FAILED',
          'message': 'Los datos proporcionados no son válidos.',
          'meta': {'errors': errores},
        };
      case 'PUT':
        datos = Map<String, dynamic>.from(options.data as Map);
        cuerpo = _respuesta();
      case 'DELETE':
        datos = null;
        codigo = 204;
      default:
        cuerpo = _respuesta();
    }
    return ResponseBody.fromString(
      cuerpo == null ? '' : jsonEncode(cuerpo),
      codigo,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  Map<String, dynamic> _respuesta() => {
    'data': datos,
    'meta': {'automatica_disponible': automatica},
  };

  @override
  void close({bool force = false}) {}
}

/// Cliente de un negocio que factura (México, con la facturación lista).
final _cliente = Sesion.desdeJson(
  'demo',
  't',
  {
    'nombre': 'Ana',
    'rol': 'miembro',
    'roles': ['miembro'],
  },
  {'factura_posible': true, 'facturacion_disponible': true},
);

Future<void> _montar(
  WidgetTester tester,
  _ApiFiscal api, {
  Sesion? sesion,
}) async {
  // Pantalla alta: todo el perfil a la vista.
  tester.view.physicalSize = const Size(800, 4000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      // Cada montaje con su sesión (otro contenedor).
      key: UniqueKey(),
      overrides: [
        sesionInicialProvider.overrideWithValue(sesion ?? _cliente),
        datosFiscalesRepositoryProvider.overrideWithValue(
          DatosFiscalesRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
      ],
      child: const MaterialApp(home: PerfilScreen()),
    ),
  );
  await tester.pumpAndSettle();
}

/// El texto de error que muestra el campo con esa llave.
String? _errorDe(WidgetTester tester, String llave) {
  final campo = find.byKey(Key(llave));
  final decorador = tester.widget<InputDecorator>(
    find.descendant(of: campo, matching: find.byType(InputDecorator)).first,
  );
  return decorador.decoration.errorText;
}

void main() {
  group('la sesión', () {
    test('sabe si el negocio factura y lo recuerda', () {
      expect(_cliente.facturaPosible, isTrue);
      expect(Sesion.desdeAlmacen(_cliente.aJson())!.facturaPosible, isTrue);
      expect(
        _cliente.conUsuario(const {'nombre': 'Ana'}).facturaPosible,
        isTrue,
      );
      // Sin la facturación de la plataforma lista, o fuera de México, no.
      expect(
        Sesion.desdeJson('demo', 't', {}, {
          'factura_posible': true,
          'facturacion_disponible': false,
        }).facturaPosible,
        isFalse,
      );
      expect(
        Sesion.desdeJson('demo', 't', {}, {
          'factura_posible': false,
        }).facturaPosible,
        isFalse,
      );
      expect(Sesion.desdeJson('demo', 't', {}).facturaPosible, isFalse);
    });
  });

  group('los catálogos del SAT', () {
    test('el RFC dice si es persona física o moral', () {
      expect(tipoPersonaDeRfc('goma800101ab1'), TipoPersona.fisica);
      expect(tipoPersonaDeRfc('ABC800101AB1'), TipoPersona.moral);
      expect(tipoPersonaDeRfc('ABC'), isNull);
    });

    test('cada quien ve lo suyo y se conserva lo elegido', () {
      final morales = clavesPara(usosCfdi, TipoPersona.moral);
      expect(morales.map((c) => c.clave), contains('G03'));
      expect(morales.map((c) => c.clave), isNot(contains('D01')));
      expect(
        clavesPara(
          usosCfdi,
          TipoPersona.moral,
          actual: 'D01',
        ).map((c) => c.clave),
        contains('D01'),
      );
      expect(clavesPara(regimenesFiscales, null), regimenesFiscales);
      expect(
        regimenesFiscales.firstWhere((r) => r.clave == '626').etiqueta,
        '626 · Régimen Simplificado de Confianza',
      );
      // Los mismos del API (CatalogosSat).
      expect(regimenesFiscales, hasLength(19));
      expect(usosCfdi, hasLength(24));
    });
  });

  group('las llamadas', () {
    test('lee sus datos y si la factura automática está disponible', () async {
      final api = _ApiFiscal(datos: _guardados, automatica: true);
      final repo = DatosFiscalesRepository(
        Dio()..httpClientAdapter = api,
        'demo',
      );

      final mios = await repo.cargar();

      expect(api.pedidos.single.method, 'GET');
      expect(api.pedidos.single.path, '/api/v1/app/demo/mi/datos-fiscales');
      expect(mios.automaticaDisponible, isTrue);
      expect(mios.datos?.rfc, 'GOMA800101AB1');
      expect(mios.datos?.facturaAutomatica, isTrue);
      expect(
        MisDatosFiscales.desdeJson({'data': null, 'meta': {}}).datos,
        isNull,
      );
    });

    test('guarda en mayúsculas y sin correo vacío; borra con DELETE', () async {
      final api = _ApiFiscal();
      final repo = DatosFiscalesRepository(
        Dio()..httpClientAdapter = api,
        'demo',
      );

      await repo.guardar(
        const DatosFiscales(
          rfc: ' goma800101ab1 ',
          nombre: 'Ana Gómez',
          codigoPostal: '06700 ',
          regimenFiscal: '612',
          usoCfdi: 'G03',
          email: '  ',
        ),
      );
      await repo.borrar();

      expect(api.pedidos[0].method, 'PUT');
      expect(api.pedidos[0].data, {
        'rfc': 'GOMA800101AB1',
        'nombre': 'ANA GÓMEZ',
        'codigo_postal': '06700',
        'regimen_fiscal': '612',
        'uso_cfdi': 'G03',
        'email': null,
        'factura_automatica': false,
      });
      expect(api.pedidos[1].method, 'DELETE');
      expect(api.pedidos[1].path, '/api/v1/app/demo/mi/datos-fiscales');
    });

    test('un 422 trae el error de cada campo', () async {
      final repo = DatosFiscalesRepository(
        Dio()
          ..httpClientAdapter = _ApiFiscal(
            errores: {
              'rfc': ['El RFC no tiene un formato válido.'],
              'uso_cfdi': [
                'Ese uso del CFDI es solo para personas físicas y el RFC es de '
                    'persona moral.',
              ],
            },
          ),
        'demo',
      );

      await expectLater(
        repo.guardar(
          const DatosFiscales(
            rfc: 'X',
            nombre: 'A',
            codigoPostal: '1',
            regimenFiscal: '601',
            usoCfdi: 'D01',
          ),
        ),
        throwsA(
          isA<DatosFiscalesInvalidos>().having((e) => e.errores, 'errores', {
            'rfc': 'El RFC no tiene un formato válido.',
            'uso_cfdi':
                'Ese uso del CFDI es solo para personas físicas y el RFC es '
                'de persona moral.',
          }),
        ),
      );
    });
  });

  group('en Mi perfil', () {
    testWidgets('solo el cliente de un negocio que factura ve la tarjeta', (
      tester,
    ) async {
      await _montar(tester, _ApiFiscal());
      expect(find.byKey(const Key('datos-facturar')), findsOneWidget);

      await _montar(
        tester,
        _ApiFiscal(),
        sesion: Sesion.desdeJson('demo', 't', {'rol': 'miembro'}, {}),
      );
      expect(find.byKey(const Key('datos-facturar')), findsNothing);

      // El equipo no (sus datos fiscales son los del negocio).
      await _montar(
        tester,
        _ApiFiscal(),
        sesion: Sesion.desdeJson(
          'demo',
          't',
          {'rol': 'recepcionista'},
          {'factura_posible': true},
        ),
      );
      expect(find.byKey(const Key('datos-facturar')), findsNothing);
    });

    testWidgets('la factura automática solo si el negocio la ofrece', (
      tester,
    ) async {
      await _montar(tester, _ApiFiscal());
      expect(find.text('Aún no los guardas'), findsOneWidget);
      expect(find.byKey(const Key('factura-automatica')), findsNothing);
      // Sin datos guardados no hay nada que borrar.
      expect(find.byKey(const Key('borrar-fiscales')), findsNothing);

      await _montar(tester, _ApiFiscal(datos: _guardados, automatica: true));
      expect(find.text('Guardados'), findsOneWidget);
      expect(
        find.text('Facturar mis pagos de citas en automático'),
        findsOneWidget,
      );
      expect(
        tester
            .widget<SwitchListTile>(find.byKey(const Key('factura-automatica')))
            .value,
        isTrue,
      );
      expect(find.text('GOMA800101AB1'), findsOneWidget);
    });

    testWidgets('guarda y, si el servidor rechaza, el error va en su campo', (
      tester,
    ) async {
      final api = _ApiFiscal(
        errores: {
          'rfc': ['El RFC no tiene un formato válido.'],
          'uso_cfdi': ['Ese uso del CFDI es solo para personas físicas.'],
        },
      );
      await _montar(tester, api);

      await tester.enterText(find.byKey(const Key('fiscal-rfc')), 'abc');
      await tester.enterText(find.byKey(const Key('fiscal-nombre')), 'Ana');
      await tester.enterText(
        find.byKey(const Key('fiscal-codigo-postal')),
        '06700',
      );
      await tester.tap(find.byKey(const Key('guardar-fiscales')));
      await tester.pumpAndSettle();

      final put = api.pedidos.where((p) => p.method == 'PUT').single;
      expect((put.data as Map)['rfc'], 'ABC');
      expect((put.data as Map)['uso_cfdi'], 'G03');
      expect(
        _errorDe(tester, 'fiscal-rfc'),
        'El RFC no tiene un formato válido.',
      );
      expect(
        _errorDe(tester, 'fiscal-uso'),
        'Ese uso del CFDI es solo para personas físicas.',
      );
      expect(_errorDe(tester, 'fiscal-codigo-postal'), isNull);
    });

    testWidgets('con datos válidos se guardan y lo dice', (tester) async {
      final api = _ApiFiscal(automatica: true);
      await _montar(tester, api);

      await tester.enterText(
        find.byKey(const Key('fiscal-rfc')),
        'goma800101ab1',
      );
      await tester.enterText(
        find.byKey(const Key('fiscal-nombre')),
        'Ana Gómez Martínez',
      );
      await tester.enterText(
        find.byKey(const Key('fiscal-codigo-postal')),
        '06700',
      );
      await tester.tap(find.byKey(const Key('fiscal-regimen')));
      await tester.pumpAndSettle();
      await tester.tap(
        find
            .text(
              '612 · Personas Físicas con Actividades Empresariales y '
              'Profesionales',
            )
            .last,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('factura-automatica')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('guardar-fiscales')));
      await tester.pumpAndSettle();

      final put = api.pedidos.where((p) => p.method == 'PUT').single;
      expect(put.data, {
        'rfc': 'GOMA800101AB1',
        'nombre': 'ANA GÓMEZ MARTÍNEZ',
        'codigo_postal': '06700',
        'regimen_fiscal': '612',
        'uso_cfdi': 'G03',
        'email': null,
        'factura_automatica': true,
      });
      expect(find.text('Datos para facturar guardados.'), findsOneWidget);
      expect(find.text('Guardados'), findsOneWidget);
    });

    testWidgets('borrar pide confirmación y deja el formulario vacío', (
      tester,
    ) async {
      final api = _ApiFiscal(datos: _guardados);
      await _montar(tester, api);

      await tester.tap(find.byKey(const Key('borrar-fiscales')));
      await tester.pumpAndSettle();
      expect(
        find.text(
          '¿Borrar tus datos para facturar? Ya no se te facturará en automático.',
        ),
        findsOneWidget,
      );
      // Cancelar no borra nada.
      await tester.tap(find.text('Cancelar'));
      await tester.pumpAndSettle();
      expect(api.pedidos.where((p) => p.method == 'DELETE'), isEmpty);

      await tester.tap(find.byKey(const Key('borrar-fiscales')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('confirmar-borrar-fiscales')));
      await tester.pumpAndSettle();

      expect(api.pedidos.where((p) => p.method == 'DELETE'), hasLength(1));
      expect(find.text('Borramos tus datos para facturar.'), findsOneWidget);
      expect(find.text('Aún no los guardas'), findsOneWidget);
      expect(find.text('GOMA800101AB1'), findsNothing);
      expect(find.byKey(const Key('borrar-fiscales')), findsNothing);
    });
  });
}
