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
import 'package:agendauno/features/cuenta/presentation/agendar_cita_sheet.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_widgets.dart';

/// Anticipo de una cita (ADR 0161): el servicio dice cuánto se paga para apartar, el
/// cliente elige pagar el anticipo (por omisión) o todo, y su cita dice lo que ya
/// pagó y lo que paga en el negocio. Datos sintéticos.

const _opciones = {
  'servicios': [
    {
      'id': 'color',
      'nombre': 'Color',
      'duracion_minutos': 60,
      'precio_minor': 120000,
      'moneda': 'MXN',
      'anticipo_minor': 30000,
    },
    {
      'id': 'corte',
      'nombre': 'Corte',
      'duracion_minutos': 30,
      'precio_minor': 25000,
      'moneda': 'MXN',
      'anticipo_minor': null,
    },
  ],
  'sucursales': [
    {'id': 's1', 'nombre': 'Centro'},
  ],
  'instructores': [
    {'id': 'p1', 'nombre': 'Ana'},
  ],
};

const _textoNota =
    'Si cancelas a tiempo, se te devuelve lo que pagaste; si cancelas tarde, '
    'el negocio lo conserva.';

/// Responde como el API y guarda lo que se le mandó al agendar; lo demás, 404.
class _Api implements HttpClientAdapter {
  final List<RequestOptions> agendadas = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final ruta = options.path;
    Object? data;
    if (options.method == 'POST' && ruta.endsWith('/mi/citas')) {
      agendadas.add(options);
      data = {'estado': 'pendiente_pago'};
    } else if (ruta.endsWith('/mi/citas/opciones')) {
      data = _opciones;
    } else if (ruta.endsWith('/mi/citas/disponibilidad')) {
      data = {
        'slots': [
          {
            'inicia': '2030-01-07T16:00:00+00:00',
            'inicia_local': '2030-01-07T10:00',
          },
        ],
      };
    }

    return ResponseBody.fromString(
      jsonEncode(data == null ? {'message': 'No'} : {'data': data}),
      data == null ? 404 : 200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

class _CuentaFalsa extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);
}

const _sesion = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Vale',
  rol: 'miembro',
);

/// Monta la app con un botón que abre [hoja] como en la cuenta.
Future<void> _montar(
  WidgetTester tester,
  _Api api,
  void Function(BuildContext context) abrir,
) async {
  tester.view.physicalSize = const Size(390, 1800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(_sesion),
        cuentaRepositoryProvider.overrideWithValue(
          CuentaRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
        cuentaProvider.overrideWith(_CuentaFalsa.new),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => abrir(context),
              child: const Text('Abrir'),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('Abrir'));
  await tester.pumpAndSettle();
}

Future<void> _abrirAgendar(WidgetTester tester, _Api api) => _montar(
  tester,
  api,
  (context) => showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (_) => const AgendarCitaSheet(),
  ),
);

Future<void> _elegirServicio(WidgetTester tester, String nombre) async {
  await tester.tap(find.byType(DropdownButtonFormField<OpcionCita>).first);
  await tester.pumpAndSettle();
  await tester.tap(find.text(nombre).last);
  await tester.pumpAndSettle();
}

/// Elige día (el que ofrece el calendario) y hora, y agenda.
Future<void> _agendar(WidgetTester tester) async {
  await tester.tap(find.text('Elegir día'));
  await tester.pumpAndSettle();
  await tester.tap(find.text('OK'));
  await tester.pumpAndSettle();
  await tester.ensureVisible(find.text('10:00'));
  await tester.tap(find.text('10:00'));
  await tester.pumpAndSettle();
  final boton = find.widgetWithText(FilledButton, 'Agendar');
  await tester.ensureVisible(boton);
  await tester.tap(boton);
  await tester.pumpAndSettle();
}

void main() {
  group('el servicio con anticipo', () {
    test('trae su precio, su moneda y su anticipo', () {
      final s = OpcionCita.desdeJson(
        (_opciones['servicios']! as List).first as Map<String, dynamic>,
      );

      expect(s.precioMinor, 120000);
      expect(s.moneda, 'MXN');
      expect(s.anticipoMinor, 30000);
      expect(s.anticipoAlAgendar, 30000);
    });

    test('sin el campo (API anterior) o null, no hay anticipo', () {
      final sinCampo = OpcionCita.desdeJson({
        'id': 'a',
        'nombre': 'Corte',
        'precio_minor': 25000,
      });
      final nulo = OpcionCita.desdeJson({
        'id': 'b',
        'nombre': 'Barba',
        'precio_minor': 15000,
        'anticipo_minor': null,
      });

      expect(sinCampo.anticipoMinor, isNull);
      expect(sinCampo.anticipoAlAgendar, isNull);
      expect(nulo.anticipoAlAgendar, isNull);
      // Sin moneda en la respuesta, la del negocio.
      expect(
        OpcionCita.desdeJson({'id': 'c', 'nombre': 'X'}, moneda: 'COP').moneda,
        'COP',
      );
    });

    test('con su bono, o si cubre todo el precio, no se ofrece', () {
      final conBono = OpcionCita.desdeJson({
        'id': 'a',
        'nombre': 'Color',
        'precio_minor': 120000,
        'anticipo_minor': 30000,
        'con_plan': true,
      });
      final completo = OpcionCita.desdeJson({
        'id': 'b',
        'nombre': 'Color',
        'precio_minor': 30000,
        'anticipo_minor': 30000,
      });

      expect(conBono.anticipoAlAgendar, isNull);
      expect(completo.anticipoAlAgendar, isNull);
    });
  });

  group('su cita con anticipo', () {
    Map<String, dynamic> reserva(Map<String, dynamic> cita) => {
      'id': 'r1',
      'estado': 'confirmada',
      'tipo': 'cita',
      'cita': {'reserva_id': 'r1', 'estado': 'confirmada', ...cita},
    };

    test('trae lo pagado, lo que falta y la orden del resto', () {
      final cita = ReservaMiembro.desdeJson(
        reserva({
          'anticipo_minor': 30000,
          'saldo_minor': 90000,
          'saldo_orden_id': '01JORDENSALDO',
        }),
      ).cita!;

      expect(cita.anticipoMinor, 30000);
      expect(cita.saldoMinor, 90000);
      expect(cita.saldoOrdenId, '01JORDENSALDO');
      expect(
        cita.textoAnticipo('MXN'),
        'Pagaste \$300.00 de anticipo; el resto (\$900.00) lo pagas en el negocio.',
      );
    });

    test('sin los campos (API anterior) o sin anticipo pagado, nada', () {
      final sinCampos = ReservaMiembro.desdeJson(reserva({})).cita!;
      final sinPagar = ReservaMiembro.desdeJson(
        reserva({'anticipo_minor': null, 'saldo_minor': 90000}),
      ).cita!;

      expect(sinCampos.anticipoMinor, isNull);
      expect(sinCampos.saldoMinor, isNull);
      expect(sinCampos.saldoOrdenId, isNull);
      expect(sinCampos.textoAnticipo('MXN'), isNull);
      expect(sinPagar.textoAnticipo('MXN'), isNull);
    });
  });

  group('al agendar', () {
    late _Api api;
    late CuentaRepository repo;

    setUp(() {
      api = _Api();
      repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');
    });

    test('manda cuánto pagar solo si se lo dicen', () async {
      Future<Map<String, dynamic>> agendar({String? pagar}) async {
        await repo.agendarCita(
          servicioId: 'color',
          sucursalId: 's1',
          profesionalId: 'p1',
          iniciaEnLocal: '2030-01-07T10:00',
          duracionMinutos: 60,
          pagar: pagar,
        );
        return api.agendadas.last.data as Map<String, dynamic>;
      }

      expect((await agendar()).containsKey('pagar'), isFalse);
      expect((await agendar(pagar: 'anticipo'))['pagar'], 'anticipo');
      expect((await agendar(pagar: 'todo'))['pagar'], 'todo');
    });

    testWidgets('con anticipo, por omisión paga el anticipo', (tester) async {
      await _abrirAgendar(tester, api);
      expect(find.byKey(const Key('elegir-anticipo')), findsNothing);

      await _elegirServicio(tester, 'Color');

      expect(find.byKey(const Key('elegir-anticipo')), findsOneWidget);
      expect(find.text('Pagar el anticipo: \$300.00'), findsOneWidget);
      expect(
        find.text('El resto (\$900.00) lo pagas en el negocio.'),
        findsOneWidget,
      );
      expect(find.text('Pagar todo ahora: \$1,200.00'), findsOneWidget);
      expect(find.text(_textoNota), findsOneWidget);

      await _agendar(tester);

      final enviado = api.agendadas.single.data as Map<String, dynamic>;
      expect(enviado['oferta_id'], 'color');
      expect(enviado['pagar'], 'anticipo');
      expect(
        find.text(
          'Tu cita quedó apartada. Paga el anticipo para confirmarla; '
          'el resto lo pagas en el negocio.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('puede pagar todo ahora', (tester) async {
      await _abrirAgendar(tester, api);
      await _elegirServicio(tester, 'Color');
      await tester.tap(find.text('Pagar todo ahora: \$1,200.00'));
      await tester.pumpAndSettle();

      await _agendar(tester);

      final enviado = api.agendadas.single.data as Map<String, dynamic>;
      expect(enviado['pagar'], 'todo');
    });

    testWidgets('al cambiar de servicio se vuelve a pagar el anticipo', (
      tester,
    ) async {
      await _abrirAgendar(tester, api);
      await _elegirServicio(tester, 'Color');
      await tester.tap(find.text('Pagar todo ahora: \$1,200.00'));
      await tester.pumpAndSettle();
      bool? elegido() => tester
          .widget<RadioGroup<bool>>(find.byType(RadioGroup<bool>))
          .groupValue;
      expect(elegido(), isTrue);

      await _elegirServicio(tester, 'Corte');
      expect(find.byKey(const Key('elegir-anticipo')), findsNothing);

      await _elegirServicio(tester, 'Color');
      expect(elegido(), isFalse);
    });

    testWidgets('sin anticipo no pregunta ni manda cuánto pagar', (
      tester,
    ) async {
      await _abrirAgendar(tester, api);
      await _elegirServicio(tester, 'Corte');
      expect(find.byKey(const Key('elegir-anticipo')), findsNothing);

      await _agendar(tester);

      final enviado = api.agendadas.single.data as Map<String, dynamic>;
      expect(enviado['oferta_id'], 'corte');
      expect(enviado.containsKey('pagar'), isFalse);
    });
  });

  testWidgets('el detalle de su cita dice lo que pagó y lo que falta', (
    tester,
  ) async {
    final reserva = ReservaMiembro.desdeJson({
      'id': 'r1',
      'estado': 'confirmada',
      'tipo': 'cita',
      'oferta': 'Color',
      'cita': {
        'reserva_id': 'r1',
        'estado': 'confirmada',
        'anticipo_minor': 30000,
        'saldo_minor': 90000,
        'saldo_orden_id': '01JORDENSALDO',
      },
    });

    await _montar(tester, _Api(), (context) {
      mostrarDetalleReserva(context, reserva);
    });

    expect(
      find.text(
        'Pagaste \$300.00 de anticipo; el resto (\$900.00) lo pagas en el negocio.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('una cita sin anticipo no lo menciona', (tester) async {
    final reserva = ReservaMiembro.desdeJson({
      'id': 'r2',
      'estado': 'confirmada',
      'tipo': 'cita',
      'oferta': 'Corte',
      'cita': {'reserva_id': 'r2', 'estado': 'confirmada'},
    });

    await _montar(tester, _Api(), (context) {
      mostrarDetalleReserva(context, reserva);
    });

    expect(find.byKey(const Key('anticipo-pagado')), findsNothing);
  });
}
