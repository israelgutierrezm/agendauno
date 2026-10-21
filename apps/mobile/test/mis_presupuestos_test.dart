import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/data/presupuestos.dart';
import 'package:agendauno/features/cuenta/presentation/mis_presupuestos.dart';
import 'package:agendauno/features/cuenta/presentation/pagos_tab.dart';

/// «Mis presupuestos» en la app (negocios de citas), como en la web: los que le
/// mandó el negocio, con sus conceptos y totales en centavos (sin punto flotante),
/// aceptarlos con la revisión que vio, rechazarlos con motivo, pagarlos en línea y
/// agendar sus servicios. Datos sintéticos, sin red real.

Map<String, dynamic> _enviado({int revision = 1, int total = 116000}) => {
  'id': 'P1',
  'folio': 'PRE-0007',
  'estado': 'enviado',
  'vencido': false,
  'valido_hasta': '2026-10-24',
  'revision': revision,
  'cliente': 'Vale Ruiz',
  'sucursal': {'id': 's1', 'nombre': 'Centro'},
  'moneda': 'MXN',
  'impuestos': 'desglosados',
  'tasa_impuesto': '16 %',
  'subtotal_minor': 110000,
  'descuento_minor': 10000,
  'impuesto_minor': 16000,
  'total_minor': total,
  'notas': 'Incluye lavado.',
  'respondido_en': null,
  'respondido_por': null,
  'lineas': [
    {
      'tipo': 'servicio',
      'descripcion': 'Corte',
      'cantidad': 2,
      'precio_unitario_minor': 25000,
      'descuento_minor': 5000,
      'subtotal_minor': 45000,
    },
    {
      'tipo': 'servicio',
      'descripcion': 'Color',
      'cantidad': 1,
      'precio_unitario_minor': 65000,
      'descuento_minor': 0,
      'subtotal_minor': 65000,
    },
  ],
  'servicios': [
    {'id': 'corte', 'nombre': 'Corte'},
    {'id': 'color', 'nombre': 'Color'},
  ],
  'puede_responder': true,
  'orden': null,
  'puede_pagar': false,
};

Map<String, dynamic> _aceptado({bool puedePagar = true}) => {
  ..._enviado(),
  'estado': 'convertido',
  'respondido_en': '2026-10-01T12:00:00-06:00',
  'respondido_por': 'cliente',
  'puede_responder': false,
  'orden': {'id': 'O1', 'estado': 'pendiente'},
  'puede_pagar': puedePagar,
};

/// API falso: anota cada petición y responde como `/mi/presupuestos*` (y lo que
/// pide agendar una cita); lo demás, 404.
class _ApiPresupuestos implements HttpClientAdapter {
  _ApiPresupuestos(
    this.presupuestos, {
    this.cambiado = false,
    this.sinCheckout = false,
  });

  List<Map<String, dynamic>> presupuestos;

  /// El negocio lo cambió después de que el cliente lo vio: aceptar da 409.
  bool cambiado;

  /// La pasarela no devolvió a dónde ir.
  final bool sinCheckout;
  final pedidos = <RequestOptions>[];

  Iterable<RequestOptions> get escrituras =>
      pedidos.where((p) => p.method == 'POST');

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    final ruta = options.path;
    var estado = 200;
    Object? cuerpo;
    if (ruta.endsWith('/mi/presupuestos')) {
      cuerpo = {'data': presupuestos};
    } else if (ruta.endsWith('/mi/presupuestos/P1')) {
      cuerpo = {'data': presupuestos.first};
    } else if (ruta.endsWith('/P1/aceptar')) {
      if (cambiado) {
        presupuestos = [_enviado(revision: 2, total: 99000)];
        estado = 409;
        cuerpo = {
          'code': 'QUOTE_NOT_ALLOWED',
          'message': 'Alguien cambió este presupuesto desde que lo revisaste.',
          'meta': {'motivo': 'cambio'},
        };
      } else {
        presupuestos = [_aceptado()];
        cuerpo = {'data': presupuestos.first};
      }
    } else if (ruta.endsWith('/P1/rechazar')) {
      presupuestos = [
        {
          ..._enviado(),
          'estado': 'rechazado',
          'puede_responder': false,
          'respondido_por': 'cliente',
        },
      ];
      cuerpo = {'data': presupuestos.first};
    } else if (ruta.endsWith('/P1/pagar')) {
      estado = 201;
      cuerpo = {
        'data': {
          'pago': 'PAGO1',
          'estado': 'pendiente',
          'checkout': sinCheckout
              ? null
              : {'tipo': 'redirect', 'url': 'https://pago.test/abc'},
        },
      };
    } else if (ruta.endsWith('/mi/citas/opciones')) {
      cuerpo = {
        'data': {
          'servicios': [
            {'id': 'color', 'nombre': 'Color', 'duracion_minutos': 60},
            {'id': 'corte', 'nombre': 'Corte', 'duracion_minutos': 30},
          ],
          'sucursales': [
            {'id': 's1', 'nombre': 'Centro'},
            {'id': 's2', 'nombre': 'Norte'},
          ],
          'instructores': [
            {'id': 'p1', 'nombre': 'Ana'},
          ],
        },
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

/// La cuenta, sin red: solo cuenta cuántas veces se recargó.
var _recargas = 0;

class _CuentaFalsa extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);

  @override
  Future<void> recargar() async => _recargas++;
}

const _vale = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Vale',
  rol: 'miembro',
  modalidad: Modalidad.citas,
);

Future<void> _montar(
  WidgetTester tester,
  _ApiPresupuestos api, {
  Widget? hijo,
  Sesion sesion = _vale,
  List<Uri>? abiertas,
}) async {
  _recargas = 0;
  tester.view.physicalSize = const Size(420, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      // Cada montaje, otra sesión desde cero.
      key: UniqueKey(),
      overrides: [
        sesionInicialProvider.overrideWithValue(sesion),
        cuentaRepositoryProvider.overrideWithValue(
          CuentaRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
        cuentaProvider.overrideWith(_CuentaFalsa.new),
      ],
      child: MaterialApp(
        home: Scaffold(
          body:
              hijo ??
              SingleChildScrollView(
                child: MisPresupuestosSeccion(
                  abrir: (url) async {
                    abiertas?.add(url);
                    return true;
                  },
                ),
              ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  group('el presupuesto', () {
    test('trae sus conceptos, totales en centavos y lo que puede hacer', () {
      final p = PresupuestoCliente.desdeJson(_enviado());

      expect(p.folio, 'PRE-0007');
      expect(p.revision, 1);
      expect(p.sucursal?.id, 's1');
      expect(p.totalMinor, 116000);
      expect(p.impuestosDesglosados, isTrue);
      expect(p.lineas.first.texto, 'Corte × 2');
      expect(p.lineas.last.texto, 'Color');
      expect(p.servicios.map((s) => s.id), ['corte', 'color']);
      expect(p.vigencia, 'Vale hasta el 24 de octubre');
      expect(p.situacion, SituacionPresupuesto.porResponder);
      expect(p.puedeAgendar, isFalse);
      expect(p.porPagar, isFalse);
    });

    test('cómo está: aceptado, pagado, rechazado o vencido', () {
      PresupuestoCliente de(Map<String, dynamic> extra) =>
          PresupuestoCliente.desdeJson({..._enviado(), ...extra});

      final aceptado = PresupuestoCliente.desdeJson(_aceptado());
      expect(aceptado.situacion, SituacionPresupuesto.aceptado);
      expect(aceptado.porPagar, isTrue);
      expect(aceptado.puedeAgendar, isTrue);
      expect(aceptado.clavePago, 'presupuesto-P1-O1');
      expect(
        de({
          'estado': 'convertido',
          'orden': {'id': 'O1', 'estado': 'pagada'},
        }).situacion.texto,
        'Aceptado y pagado',
      );
      expect(de({'estado': 'rechazado'}).situacion.texto, 'Rechazado');
      final vencido = de({'vencido': true, 'puede_responder': false});
      expect(vencido.situacion.texto, 'Vencido');
      expect(vencido.vencioSinResponder, isTrue);
    });

    test('sin moneda, la del negocio; sin notas ni sede, nada', () {
      final p = PresupuestoCliente.desdeJson({
        'id': 'x',
        'folio': 'F',
        'estado': 'enviado',
        'valido_hasta': '2026-11-01',
        'revision': 3,
        'total_minor': 5000,
        'notas': '  ',
      }, moneda: 'COP');

      expect(p.moneda, 'COP');
      expect(p.notas, isNull);
      expect(p.sucursal, isNull);
      expect(p.orden, isNull);
      expect(p.lineas, isEmpty);
    });
  });

  group('las llamadas', () {
    test('lista, lee, acepta con su revisión, rechaza y paga', () async {
      final api = _ApiPresupuestos([_enviado()]);
      final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

      final lista = await repo.presupuestos();
      await repo.presupuesto('P1');
      final aceptado = await repo.aceptarPresupuesto('P1', 1);
      await repo.rechazarPresupuesto('P1', motivo: 'Muy caro');
      final checkout = await repo.pagarPresupuesto(aceptado);

      expect(lista.single.id, 'P1');
      expect(aceptado.situacion, SituacionPresupuesto.aceptado);
      expect(api.pedidos.map((p) => '${p.method} ${p.path}'), [
        'GET /api/v1/app/demo/mi/presupuestos',
        'GET /api/v1/app/demo/mi/presupuestos/P1',
        'POST /api/v1/app/demo/mi/presupuestos/P1/aceptar',
        'POST /api/v1/app/demo/mi/presupuestos/P1/rechazar',
        'POST /api/v1/app/demo/mi/presupuestos/P1/pagar',
      ]);
      expect(api.pedidos[2].data, {'revision': 1});
      expect(api.pedidos[3].data, {'motivo': 'Muy caro'});
      // La misma clave en cada intento: no se inicia otro pago (como la web).
      expect(api.pedidos[4].data, {'idempotency_key': 'presupuesto-P1-O1'});
      expect(checkout, isA<IrAPagarEnLinea>());
      expect((checkout as IrAPagarEnLinea).url, 'https://pago.test/abc');
    });

    test('rechazar sin motivo lo manda vacío', () async {
      final api = _ApiPresupuestos([_enviado()]);
      final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

      await repo.rechazarPresupuesto('P1');

      expect(api.pedidos.single.data, {'motivo': null});
    });
  });

  group('en la pantalla', () {
    testWidgets('el que espera respuesta aparece abierto, con sus totales', (
      tester,
    ) async {
      await _montar(tester, _ApiPresupuestos([_enviado()]));

      expect(find.text('Mis presupuestos'), findsOneWidget);
      expect(find.text('PRE-0007'), findsOneWidget);
      expect(find.text('Vale hasta el 24 de octubre'), findsOneWidget);
      expect(find.text('Por responder'), findsOneWidget);
      expect(find.text('Corte × 2'), findsOneWidget);
      expect(find.text('Descuento: −\$50.00'), findsOneWidget);
      expect(find.text('Impuestos (16 %)'), findsOneWidget);
      // El total arriba y en el desglose.
      expect(find.text('\$1,160.00'), findsNWidgets(2));
      expect(find.text('Incluye lavado.'), findsOneWidget);
      expect(find.text('Aceptar presupuesto'), findsOneWidget);
      // Sus servicios se agendan hasta que lo acepta.
      expect(find.text('Agendar Corte'), findsNothing);
    });

    testWidgets('sin presupuestos, la sección no aparece', (tester) async {
      await _montar(tester, _ApiPresupuestos([]));

      expect(find.text('Mis presupuestos'), findsNothing);
    });

    testWidgets(
      'aceptarlo pide confirmación, manda la revisión y queda por pagar',
      (tester) async {
        final api = _ApiPresupuestos([_enviado()]);
        await _montar(tester, api);

        await tester.tap(find.text('Aceptar presupuesto'));
        await tester.pumpAndSettle();
        expect(find.textContaining('¿Aceptas este presupuesto?'), findsOne);
        await tester.tap(find.text('Aceptar presupuesto').last);
        await tester.pumpAndSettle();

        final aceptar = api.escrituras.single;
        expect(aceptar.path, endsWith('/mi/presupuestos/P1/aceptar'));
        expect(aceptar.data, {'revision': 1});
        expect(find.text('Aceptado'), findsOneWidget);
        expect(find.text('Pagar en línea'), findsOneWidget);
        expect(find.text('Agendar Corte'), findsOneWidget);
        expect(find.text('Agendar Color'), findsOneWidget);
        // Su cobro aparece en «Por pagar».
        expect(_recargas, 1);
      },
    );

    testWidgets('volver en la confirmación no lo acepta', (tester) async {
      final api = _ApiPresupuestos([_enviado()]);
      await _montar(tester, api);

      await tester.tap(find.text('Aceptar presupuesto'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Volver'));
      await tester.pumpAndSettle();

      expect(api.escrituras, isEmpty);
      expect(find.text('Por responder'), findsOneWidget);
    });

    testWidgets('si el negocio lo cambió (409), avisa y muestra el actual', (
      tester,
    ) async {
      final api = _ApiPresupuestos([_enviado()], cambiado: true);
      await _montar(tester, api);

      await tester.tap(find.text('Aceptar presupuesto'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Aceptar presupuesto').last);
      await tester.pumpAndSettle();

      expect(
        find.text(
          'El negocio cambió este presupuesto. Revisa la versión actual antes '
          'de aceptarlo.',
        ),
        findsOneWidget,
      );
      expect(api.pedidos.last.path, endsWith('/mi/presupuestos/P1'));
      expect(find.text('\$990.00'), findsNWidgets(2));
      expect(find.text('Por responder'), findsOneWidget);

      // Aceptar de nuevo manda la revisión nueva.
      api.cambiado = false;
      await tester.tap(find.text('Aceptar presupuesto'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Aceptar presupuesto').last);
      await tester.pumpAndSettle();
      expect(api.escrituras.last.data, {'revision': 2});
      expect(find.text('Aceptado'), findsOneWidget);
    });

    testWidgets('rechazarlo con su motivo', (tester) async {
      final api = _ApiPresupuestos([_enviado()]);
      await _montar(tester, api);

      await tester.tap(find.text('Rechazar'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('presupuesto-motivo')),
        '  Muy caro ',
      );
      await tester.tap(find.text('Rechazar presupuesto'));
      await tester.pumpAndSettle();

      final rechazar = api.escrituras.single;
      expect(rechazar.path, endsWith('/mi/presupuestos/P1/rechazar'));
      expect(rechazar.data, {'motivo': 'Muy caro'});
      expect(find.text('Rechazado'), findsOneWidget);
      expect(find.text('Aceptar presupuesto'), findsNothing);
    });

    testWidgets('pagarlo en línea abre la pasarela en el navegador', (
      tester,
    ) async {
      final api = _ApiPresupuestos([_aceptado()]);
      final abiertas = <Uri>[];
      await _montar(tester, api, abiertas: abiertas);
      // Ya respondido: se abre al tocarlo.
      await tester.tap(find.text('PRE-0007'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Pagar en línea'));
      await tester.pumpAndSettle();

      expect(api.escrituras.single.path, endsWith('/P1/pagar'));
      expect(abiertas, [Uri.parse('https://pago.test/abc')]);
      expect(
        find.text(
          'Completa el pago en el navegador; al volver actualizamos tu cuenta.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('si la pasarela no da a dónde ir, lo dice y no abre nada', (
      tester,
    ) async {
      final abiertas = <Uri>[];
      await _montar(
        tester,
        _ApiPresupuestos([_aceptado()], sinCheckout: true),
        abiertas: abiertas,
      );
      await tester.tap(find.text('PRE-0007'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Pagar en línea'));
      await tester.pumpAndSettle();

      expect(abiertas, isEmpty);
      expect(
        find.text('No se pudo abrir el pago en línea: inténtalo de nuevo.'),
        findsOneWidget,
      );
    });

    testWidgets('si el negocio no cobra en línea, se paga en el negocio', (
      tester,
    ) async {
      await _montar(tester, _ApiPresupuestos([_aceptado(puedePagar: false)]));
      await tester.tap(find.text('PRE-0007'));
      await tester.pumpAndSettle();

      expect(find.text('Pagar en línea'), findsNothing);
      expect(find.text('Págalo en el negocio cuando vayas.'), findsOneWidget);
    });

    testWidgets('agendar un servicio abre la cita con él y su sede elegidos', (
      tester,
    ) async {
      final api = _ApiPresupuestos([_aceptado()]);
      await _montar(tester, api);
      await tester.tap(find.text('PRE-0007'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Agendar Corte'));
      await tester.pumpAndSettle();

      expect(find.text('Agendar una cita'), findsOneWidget);
      final servicio = tester.widget<DropdownButtonFormField<OpcionCita>>(
        find.byType(DropdownButtonFormField<OpcionCita>).first,
      );
      final sede = tester.widget<DropdownButtonFormField<OpcionCita>>(
        find.byType(DropdownButtonFormField<OpcionCita>).at(1),
      );
      expect(servicio.initialValue?.id, 'corte');
      expect(sede.initialValue?.id, 's1');
    });

    testWidgets('en Pagos solo aparece en un negocio de citas', (tester) async {
      final enCitas = _ApiPresupuestos([_enviado()]);
      await _montar(
        tester,
        enCitas,
        hijo: const PagosTab(
          cuenta: MiCuenta(derechos: [], reservas: [], clases: []),
        ),
      );
      expect(find.text('Mis presupuestos'), findsOneWidget);
      // Arriba de lo que tiene por pagar.
      expect(
        tester.getTopLeft(find.text('Mis presupuestos')).dy,
        lessThan(tester.getTopLeft(find.text('Por pagar')).dy),
      );

      final enClases = _ApiPresupuestos([_enviado()]);
      await _montar(
        tester,
        enClases,
        sesion: const Sesion(
          slug: 'demo',
          bearer: 't',
          nombre: 'Vale',
          rol: 'miembro',
          modalidad: Modalidad.clases,
        ),
        hijo: const PagosTab(
          cuenta: MiCuenta(derechos: [], reservas: [], clases: []),
        ),
      );
      expect(find.text('Mis presupuestos'), findsNothing);
      expect(
        enClases.pedidos.where((p) => p.path.contains('presupuestos')),
        isEmpty,
      );
    });
  });
}
