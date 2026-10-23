import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/formato.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/caja/data/caja_models.dart';
import 'package:agendauno/features/caja/data/caja_repository.dart';
import 'package:agendauno/features/caja/presentation/caja_screen.dart';
import 'package:agendauno/features/inicio/data/inicio_repository.dart';
import 'package:agendauno/features/inicio/data/resumen_hoy.dart';
import 'package:agendauno/features/inicio/presentation/equipo_screen.dart';

/// La caja de la sucursal en la app del equipo (ADR 0175): las mismas rutas que la
/// web (`/caja`, `/caja/turnos…`, permiso `caja.operar`), abrir con el fondo, mover
/// efectivo con su clave de reintento, contar a ciegas y cerrar con el motivo si no
/// cuadró. API falso con su estado; datos sintéticos, sin red real.

Map<String, dynamic> _turnoJson({
  String estado = 'abierta',
  int fondo = 50000,
  Map<String, int>? esperado,
  List<Map<String, dynamic>> movimientos = const [],
  List<Map<String, dynamic>> conteos = const [],
  int? diferencia,
}) => {
  'id': 'T1',
  'sucursal': {'id': 's1', 'nombre': 'Centro'},
  'estado': estado,
  'moneda': 'MXN',
  'fondo_minor': fondo,
  'nota_apertura': null,
  'abierta_por': 'Rita Recepción',
  'abierta_en': '2026-10-01T12:00:00Z',
  'contada_en': null,
  'cerrada_por': null,
  'cerrada_en': null,
  'diferencia_minor': diferencia,
  'motivo_diferencia': null,
  'requiere_revision': estado == 'cerrada' && (diferencia ?? 0) != 0,
  'revisada_por': null,
  'revisada_en': null,
  'nota_revision': null,
  'esperado': esperado,
  'conteos': conteos,
  'movimientos': movimientos,
};

/// API falso de la caja: guarda el turno de la sede y responde como el servidor.
/// Quien supervisa ve lo esperado; quien solo opera, no (a ciegas).
class _ApiCaja implements HttpClientAdapter {
  _ApiCaja({
    this.supervisa = false,
    this.sedes = const [
      {'id': 's1', 'nombre': 'Centro'},
    ],
    this.abierta = false,
    this.cuadra = false,
    this.yaAbiertaPorOtro = false,
  });

  final bool supervisa;
  final List<Map<String, String>> sedes;
  bool abierta;
  final bool cuadra;

  /// Al abrir, otra persona ya la abrió: 409.
  bool yaAbiertaPorOtro;
  String estado = 'abierta';
  final movimientos = <Map<String, dynamic>>[];
  final pedidos = <RequestOptions>[];

  Iterable<RequestOptions> de(String fin) =>
      pedidos.where((p) => p.path.endsWith(fin));

  int get _efectivo =>
      50000 + movimientos.fold<int>(0, (a, m) => a + (m['monto_minor'] as int));

  Map<String, dynamic> _turno() => _turnoJson(
    estado: estado,
    esperado: supervisa && estado == 'abierta'
        ? {
            'efectivo': _efectivo,
            'tarjeta': 0,
            'transferencia': 0,
            'ventanilla': 0,
          }
        : null,
    movimientos: estado == 'abierta' ? movimientos : const [],
    conteos: estado == 'contada'
        ? [
            {
              'metodo': 'efectivo',
              'esperado_minor': _efectivo,
              'contado_minor': _efectivo - 500,
              'diferencia_minor': -500,
            },
            for (final m in ['tarjeta', 'transferencia', 'ventanilla'])
              {
                'metodo': m,
                'esperado_minor': 0,
                'contado_minor': 0,
                'diferencia_minor': 0,
              },
          ]
        : const [],
    diferencia: estado == 'abierta' && !supervisa
        ? null
        : (estado == 'cerrada' && cuadra ? 0 : -500),
  );

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    final ruta = options.path;
    var codigo = 200;
    Object cuerpo;
    if (ruta.endsWith('/sucursales')) {
      cuerpo = {'data': sedes};
    } else if (ruta.endsWith('/caja')) {
      cuerpo = {
        'data': {
          'sucursal': {'id': options.queryParameters['sucursal_id']},
          'usa_caja': abierta || estado != 'abierta',
          'turno': abierta ? _turno() : null,
        },
      };
    } else if (ruta.endsWith('/caja/turnos')) {
      if (yaAbiertaPorOtro) {
        yaAbiertaPorOtro = false;
        abierta = true;
        codigo = 409;
        cuerpo = {
          'code': 'CASH_REGISTER_ALREADY_OPEN',
          'message': 'La caja de Centro sigue abierta o falta cerrarla.',
        };
      } else {
        abierta = true;
        codigo = 201;
        cuerpo = {'data': _turno()};
      }
    } else if (ruta.endsWith('/movimientos')) {
      final d = options.data as Map;
      final monto = d['monto_minor'] as int;
      movimientos.add({
        'id': 'M${movimientos.length + 1}',
        'tipo': d['tipo'],
        'metodo': 'efectivo',
        'monto_minor': d['tipo'] == 'retiro' ? -monto : monto,
        'motivo': d['motivo'],
        'quien': 'Rita Recepción',
        'fecha': '2026-10-01T13:00:00Z',
      });
      codigo = 201;
      cuerpo = {'data': _turno()};
    } else if (ruta.endsWith('/conteo')) {
      if (cuadra) {
        estado = 'cerrada';
        abierta = false;
      } else {
        estado = 'contada';
      }
      cuerpo = {
        'data': {..._turno(), 'cuadra': cuadra},
      };
    } else if (ruta.endsWith('/cierre')) {
      estado = 'cerrada';
      abierta = false;
      cuerpo = {'data': _turno()};
    } else {
      codigo = 404;
      cuerpo = {'message': 'No'};
    }
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

/// Responde siempre lo mismo (un error del servidor o sin red).
class _ApiFija implements HttpClientAdapter {
  _ApiFija({this.estado, this.cuerpo = const {}});

  /// null: sin red (la petición nunca llega).
  final int? estado;
  final Map<String, dynamic> cuerpo;
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    if (estado == null) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'sin red',
      );
    }
    return ResponseBody.fromString(
      jsonEncode(cuerpo),
      estado!,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

const _recepcion = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Rita',
  rol: 'recepcionista',
  permisos: ['caja.operar', 'sucursales.ver'],
);

const _admin = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Ana',
  rol: 'admin',
  permisos: ['caja.operar', 'caja.supervisar', 'sucursales.ver'],
);

Future<void> _montar(
  WidgetTester tester,
  _ApiCaja api, {
  Sesion sesion = _recepcion,
}) async {
  tester.view.physicalSize = const Size(420, 1400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final dio = Dio()..httpClientAdapter = api;
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(sesion),
        cajaRepositoryProvider.overrideWithValue(CajaRepository(dio, 'demo')),
        agendaRepositoryProvider.overrideWithValue(
          AgendaRepository(dio, 'demo'),
        ),
      ],
      child: const MaterialApp(home: CajaScreen()),
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
  group('los montos', () {
    test('se leen en centavos, sin punto flotante', () {
      expect(Formato.aMinor('500'), 50000);
      expect(Formato.aMinor(r'$1,299.50'), 129950);
      expect(Formato.aMinor(' 80.5 '), 8050);
      expect(Formato.aMinor('.75'), 75);
      expect(Formato.aMinor('MXN 12'), 1200);
      expect(Formato.aMinor('0'), 0);
    });

    test('lo que se presta a confusión no se lee', () {
      expect(Formato.aMinor(''), isNull);
      expect(Formato.aMinor('12,50'), isNull);
      expect(Formato.aMinor('1.250'), isNull);
      expect(Formato.aMinor('1,25,000'), isNull);
      expect(Formato.aMinor('12a'), isNull);
      expect(Formato.aMinor('-5'), isNull);
      expect(Formato.aMinor('-5', negativo: true), -500);
      expect(Formato.aMinor('9999999999999'), isNull);
    });
  });

  group('los modelos', () {
    test('a ciegas no trae lo esperado ni la diferencia', () {
      final t = TurnoCaja.desdeJson(_turnoJson());

      expect(t.abierta, isTrue);
      expect(t.esperado, isNull);
      expect(t.diferenciaMinor, isNull);
      expect(t.fondoMinor, 50000);
      expect(t.sucursal, 'Centro');
    });

    test('quien supervisa ve lo esperado; ya contada, cada forma', () {
      final abierta = TurnoCaja.desdeJson(
        _turnoJson(esperado: {'efectivo': 62000, 'tarjeta': 1500}),
      );
      final contada = TurnoCaja.desdeJson(
        _turnoJson(
          estado: 'contada',
          diferencia: -500,
          conteos: [
            {
              'metodo': 'efectivo',
              'esperado_minor': 62000,
              'contado_minor': 61500,
              'diferencia_minor': -500,
            },
          ],
          movimientos: [
            {
              'id': 'M1',
              'tipo': 'retiro',
              'metodo': 'efectivo',
              'monto_minor': -12050,
              'motivo': 'Depósito',
            },
          ],
        ),
      );

      // Las formas que no vinieron cuentan cero.
      expect(abierta.esperado, {
        'efectivo': 62000,
        'tarjeta': 1500,
        'transferencia': 0,
        'ventanilla': 0,
      });
      expect(contada.contada, isTrue);
      expect(contada.diferenciaMinor, -500);
      expect(contada.conteos.single.diferenciaMinor, -500);
      expect(contada.movimientos.single.tipoTexto, 'Retiro');
      expect(contada.movimientos.single.montoMinor, -12050);
      expect(
        CajaSede.desdeJson({'usa_caja': false, 'turno': null}).turno,
        isNull,
      );
    });
  });

  group('las llamadas', () {
    test('la caja de la sede, abrir, contar y cerrar por sus rutas', () async {
      final api = _ApiCaja(abierta: true);
      final repo = CajaRepository(Dio()..httpClientAdapter = api, 'demo');

      final caja = await repo.actual('s1');
      final abierta = await CajaRepository(
        Dio()..httpClientAdapter = _ApiCaja(),
        'demo',
      ).abrir('s1', fondoMinor: 50000, nota: '  ');
      final conteo = await repo.contar('T1', {'efectivo': 61500});
      final cerrada = await repo.cerrar('T1', ' Di mal un cambio ');

      expect(caja.usaCaja, isTrue);
      expect(caja.turno?.id, 'T1');
      expect(api.pedidos[0].method, 'GET');
      expect(api.pedidos[0].path, '/api/v1/app/demo/caja');
      expect(api.pedidos[0].queryParameters, {'sucursal_id': 's1'});
      expect(abierta.abierta, isTrue);
      expect(api.pedidos[1].method, 'POST');
      expect(api.pedidos[1].path, '/api/v1/app/demo/caja/turnos/T1/conteo');
      // Cada forma de pago, lo que falte en cero.
      expect(api.pedidos[1].data, {
        'efectivo_minor': 61500,
        'tarjeta_minor': 0,
        'transferencia_minor': 0,
        'ventanilla_minor': 0,
      });
      expect(conteo.cuadra, isFalse);
      expect(conteo.turno.contada, isTrue);
      expect(api.pedidos[2].path, '/api/v1/app/demo/caja/turnos/T1/cierre');
      expect(api.pedidos[2].data, {'motivo': 'Di mal un cambio'});
      expect(cerrada.cerrada, isTrue);
    });

    test('abrir manda la sede, el fondo en centavos y la nota', () async {
      final api = _ApiCaja();
      final repo = CajaRepository(Dio()..httpClientAdapter = api, 'demo');

      await repo.abrir('s1', fondoMinor: 50000, nota: ' Turno mañana ');

      expect(api.pedidos.single.path, '/api/v1/app/demo/caja/turnos');
      expect(api.pedidos.single.data, {
        'sucursal_id': 's1',
        'fondo_minor': 50000,
        'nota': 'Turno mañana',
      });
    });

    test('ya abierta en la sede: 409 con su mensaje', () async {
      final repo = CajaRepository(
        Dio()..httpClientAdapter = _ApiCaja(yaAbiertaPorOtro: true),
        'demo',
      );

      await expectLater(
        repo.abrir('s1', fondoMinor: 0),
        throwsA(
          isA<DioException>()
              .having((e) => e.response?.statusCode, 'estado', 409)
              .having(
                (e) => (e.response?.data as Map)['code'],
                'code',
                'CASH_REGISTER_ALREADY_OPEN',
              ),
        ),
      );
    });

    test('un movimiento lleva su clave: la misma al reintentar sin red, otra tras '
        'una respuesta', () async {
      final sinRed = _ApiFija();
      final dio = Dio()..httpClientAdapter = sinRed;
      final repo = CajaRepository(dio, 'demo');
      Future<void> mover() => repo.moverEfectivo(
        'T1',
        tipo: 'retiro',
        montoMinor: 12050,
        motivo: ' Depósito al banco ',
      );

      await expectLater(mover(), throwsA(isA<DioException>()));
      await expectLater(mover(), throwsA(isA<DioException>()));
      final primera = (sinRed.pedidos[0].data as Map)['clave'] as String;
      expect((sinRed.pedidos[1].data as Map)['clave'], primera);
      expect(primera, matches(RegExp(r'^[0-9a-f]{32}$')));
      expect(
        sinRed.pedidos[0].path,
        '/api/v1/app/demo/caja/turnos/T1/movimientos',
      );
      expect(sinRed.pedidos[0].data, {
        'tipo': 'retiro',
        'monto_minor': 12050,
        'motivo': 'Depósito al banco',
        'clave': primera,
      });

      // El servidor respondió (p. ej. la caja ya se contó): lo siguiente es otro
      // intento.
      final rechazo = _ApiFija(
        estado: 422,
        cuerpo: {
          'code': 'CASH_REGISTER_INVALID_STATE',
          'message':
              'Esta caja ya se contó: el efectivo se mueve en la caja abierta.',
        },
      );
      dio.httpClientAdapter = rechazo;
      await expectLater(mover(), throwsA(isA<DioException>()));
      await expectLater(mover(), throwsA(isA<DioException>()));
      final tercera = (rechazo.pedidos[0].data as Map)['clave'];
      expect(tercera, primera);
      expect((rechazo.pedidos[1].data as Map)['clave'], isNot(primera));
    });

    test('solo con el permiso de la caja', () {
      expect(puedeOperarCaja(_recepcion), isTrue);
      expect(puedeOperarCaja(_admin), isTrue);
      expect(
        puedeOperarCaja(
          const Sesion(
            slug: 'demo',
            bearer: 't',
            nombre: 'Iván',
            rol: 'instructor',
            permisos: ['agenda.ver'],
          ),
        ),
        isFalse,
      );
      expect(puedeOperarCaja(null), isFalse);
    });
  });

  group('en la pantalla', () {
    testWidgets('abre la caja con su fondo y queda abierta, a ciegas', (
      tester,
    ) async {
      final api = _ApiCaja();
      await _montar(tester, api);

      // Una sola sede: no se elige.
      expect(find.byKey(const Key('sede-caja')), findsNothing);
      expect(find.text('Cerrada'), findsOneWidget);
      expect(find.text('La caja de Centro está cerrada.'), findsOneWidget);

      await tester.enterText(find.byKey(const Key('fondo')), '500');
      await tester.tap(find.byKey(const Key('abrir')));
      await tester.pumpAndSettle();

      expect(api.de('/caja/turnos').single.data, {
        'sucursal_id': 's1',
        'fondo_minor': 50000,
        'nota': null,
      });
      expect(find.text('Abierta'), findsOneWidget);
      expect(find.text(r'Fondo $500.00'), findsOneWidget);
      expect(find.byKey(const Key('a-ciegas')), findsOneWidget);
      expect(find.byKey(const Key('esperado')), findsNothing);
    });

    testWidgets('un fondo que no se entiende no se manda', (tester) async {
      final api = _ApiCaja();
      await _montar(tester, api);

      await tester.enterText(find.byKey(const Key('fondo')), '12,50');
      await tester.tap(find.byKey(const Key('abrir')));
      await tester.pumpAndSettle();

      expect(api.de('/caja/turnos'), isEmpty);
      expect(find.text('Escribe un monto válido.'), findsOneWidget);
    });

    testWidgets(
      'retira efectivo, cuenta a ciegas y, si no cuadra, cierra con el motivo',
      (tester) async {
        final api = _ApiCaja(abierta: true);
        await _montar(tester, api);

        // Retiro.
        await tester.tap(find.byKey(const Key('retiro')));
        await tester.pumpAndSettle();
        expect(find.text('Retiro de efectivo'), findsOneWidget);
        await tester.enterText(
          find.byKey(const Key('monto-movimiento')),
          '120.50',
        );
        await tester.enterText(
          find.byKey(const Key('motivo-movimiento')),
          'Depósito al banco',
        );
        await tester.tap(find.byKey(const Key('registrar-movimiento')));
        await tester.pumpAndSettle();

        final mov = api.de('/movimientos').single.data as Map;
        expect(mov['tipo'], 'retiro');
        expect(mov['monto_minor'], 12050);
        expect(mov['motivo'], 'Depósito al banco');
        expect(mov['clave'], isA<String>());
        expect(find.text('Movimiento registrado.'), findsOneWidget);
        expect(find.text(r'-$120.50'), findsOneWidget);

        // Conteo: lo esperado no se ve antes de contar.
        await tester.tap(find.byKey(const Key('contar')));
        await tester.pumpAndSettle();
        expect(find.text('Contar la caja'), findsOneWidget);
        expect(find.textContaining('Esperado'), findsNothing);
        await tester.enterText(
          find.byKey(const Key('contado-efectivo')),
          '374.50',
        );
        await tester.tap(find.byKey(const Key('registrar-conteo')));
        await tester.pumpAndSettle();
        // Se confirma: el conteo es una sola vez.
        expect(find.text('¿Registrar el conteo?'), findsOneWidget);
        await tester.tap(find.byKey(const Key('confirmar-conteo')));
        await tester.pumpAndSettle();

        expect(api.de('/conteo').single.data, {
          'efectivo_minor': 37450,
          'tarjeta_minor': 0,
          'transferencia_minor': 0,
          'ventanilla_minor': 0,
        });
        // No cuadró: la diferencia de cada forma y el motivo para cerrar.
        expect(find.text('El conteo no cuadró'), findsOneWidget);
        expect(find.textContaining(r'Esperado $379.50'), findsOneWidget);
        expect(find.text(r'-$5.00'), findsOneWidget);
        final cerrar = find.byKey(const Key('cerrar-con-motivo'));
        expect(tester.widget<FilledButton>(cerrar).onPressed, isNull);

        await tester.enterText(
          find.byKey(const Key('motivo-diferencia')),
          'Di mal un cambio',
        );
        await tester.pump();
        await tester.tap(cerrar);
        await tester.pumpAndSettle();

        expect(api.de('/cierre').single.data, {'motivo': 'Di mal un cambio'});
        expect(find.text('Caja cerrada. Quedó para revisión.'), findsWidgets);
        expect(find.text(r'Diferencia total -$5.00'), findsOneWidget);
        // Cerrada: se puede abrir otra.
        expect(find.byKey(const Key('abrir-caja')), findsOneWidget);
      },
    );

    testWidgets('si el conteo cuadra, la caja queda cerrada', (tester) async {
      final api = _ApiCaja(abierta: true, cuadra: true);
      await _montar(tester, api);

      await tester.tap(find.byKey(const Key('contar')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('contado-efectivo')), '500');
      await tester.tap(find.byKey(const Key('registrar-conteo')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('confirmar-conteo')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('ultimo-cierre')), findsOneWidget);
      expect(find.text('La caja cuadró y quedó cerrada.'), findsWidgets);
      expect(api.de('/cierre'), isEmpty);
    });

    testWidgets('el efectivo contado no puede ser negativo', (tester) async {
      final api = _ApiCaja(abierta: true);
      await _montar(tester, api);

      await tester.tap(find.byKey(const Key('contar')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('contado-efectivo')), '-5');
      await tester.tap(find.byKey(const Key('registrar-conteo')));
      await tester.pumpAndSettle();

      expect(find.text('Efectivo: escribe un monto válido.'), findsOneWidget);
      expect(find.text('¿Registrar el conteo?'), findsNothing);
      expect(api.de('/conteo'), isEmpty);
    });

    testWidgets('quien supervisa ve lo esperado en vivo', (tester) async {
      await _montar(
        tester,
        _ApiCaja(abierta: true, supervisa: true),
        sesion: _admin,
      );

      expect(find.byKey(const Key('esperado')), findsOneWidget);
      expect(find.text('Esperado ahora'), findsOneWidget);
      expect(find.text(r'$500.00'), findsOneWidget);
      expect(find.byKey(const Key('a-ciegas')), findsNothing);
    });

    testWidgets('con varias sedes, primero se elige cuál', (tester) async {
      final api = _ApiCaja(
        sedes: const [
          {'id': 's1', 'nombre': 'Centro'},
          {'id': 's2', 'nombre': 'Norte'},
        ],
      );
      await _montar(tester, api);

      expect(find.text('Elige la sucursal de la caja.'), findsOneWidget);
      expect(api.de('/caja'), isEmpty);

      await tester.tap(find.byKey(const Key('sede-caja')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Norte').last);
      await tester.pumpAndSettle();

      expect(api.de('/caja').single.queryParameters, {'sucursal_id': 's2'});
      expect(find.text('La caja de Norte está cerrada.'), findsOneWidget);
    });

    testWidgets('si otra persona ya la abrió, lo dice y la muestra abierta', (
      tester,
    ) async {
      final api = _ApiCaja(yaAbiertaPorOtro: true);
      await _montar(tester, api);

      await tester.tap(find.byKey(const Key('abrir')));
      await tester.pumpAndSettle();

      expect(
        find.text('La caja de Centro sigue abierta o falta cerrarla.'),
        findsOneWidget,
      );
      expect(api.de('/caja').length, 2);
      expect(find.text('Abierta'), findsOneWidget);
    });
  });

  testWidgets('el Inicio del equipo ofrece la caja solo con su permiso', (
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
    expect(find.byKey(const Key('caja')), findsOneWidget);

    await montar(
      const Sesion(
        slug: 'demo',
        bearer: 't',
        nombre: 'Recepción',
        rol: 'rol_agenda',
        permisos: ['agenda.ver'],
      ),
    );
    expect(find.byKey(const Key('caja')), findsNothing);
  });
}
