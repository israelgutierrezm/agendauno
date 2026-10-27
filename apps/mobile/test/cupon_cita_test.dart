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

/// Cupón al agendar una cita desde la cuenta (ADR 0220): «¿Tienes un cupón?» se
/// abre, revisa el código (`GET /mi/citas/cupon`) y lo manda al agendar
/// (`POST /mi/citas`); solo en lo que se paga y se limpia al cambiar de servicio.
/// Datos sintéticos.

const _opciones = {
  'servicios': [
    {
      'id': 'corte',
      'nombre': 'Corte',
      'duracion_minutos': 30,
      'precio_minor': 25000,
      'moneda': 'MXN',
      'prepagados': 0,
    },
    {
      'id': 'barba',
      'nombre': 'Barba',
      'duracion_minutos': 30,
      'precio_minor': 15000,
      'moneda': 'MXN',
    },
    {
      'id': 'masaje',
      'nombre': 'Spa',
      'duracion_minutos': 60,
      'precio_minor': 90000,
      'moneda': 'MXN',
      'con_plan': true,
    },
    {
      'id': 'color',
      'nombre': 'Color',
      'duracion_minutos': 60,
      'precio_minor': 120000,
      'moneda': 'MXN',
      'prepagados': 2,
    },
  ],
  'sucursales': [
    {'id': 's1', 'nombre': 'Centro'},
  ],
  'instructores': [
    {'id': 'p1', 'nombre': 'Ana'},
  ],
};

/// Responde como el API: el cupón VERANO descuenta $50 (o lo que diga
/// [descuentoMinor]); cualquier otro, 422 con el motivo. Al agendar, un cupón que no
/// sirve también responde 422 si [rechazarAlAgendar].
class _Api implements HttpClientAdapter {
  _Api({this.descuentoMinor = 5000, this.rechazarAlAgendar = false});

  final int descuentoMinor;
  final bool rechazarAlAgendar;
  final List<RequestOptions> agendadas = [];
  final List<RequestOptions> revisados = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final ruta = options.path;
    var estado = 200;
    Object? cuerpo;
    if (options.method == 'POST' && ruta.endsWith('/mi/citas')) {
      agendadas.add(options);
      if (rechazarAlAgendar) {
        estado = 422;
        cuerpo = {
          'message': 'La promoción ya no tiene usos disponibles.',
          'code': 'PROMO_INVALID',
        };
      } else {
        cuerpo = {
          'data': {'estado': 'confirmada'},
        };
      }
    } else if (ruta.endsWith('/citas/cupon')) {
      revisados.add(options);
      final codigo = options.queryParameters['codigo'] as String;
      if (codigo.toUpperCase() == 'VERANO') {
        cuerpo = {
          'data': {
            'codigo': 'VERANO',
            'descuento_minor': descuentoMinor,
            'total_minor': 25000 - descuentoMinor,
          },
        };
      } else {
        estado = 422;
        cuerpo = {
          'message': 'El código de promoción no existe.',
          'code': 'PROMO_INVALID',
        };
      }
    } else if (ruta.endsWith('/mi/citas/opciones')) {
      cuerpo = {'data': _opciones};
    } else if (ruta.endsWith('/mi/citas/disponibilidad')) {
      cuerpo = {
        'data': {
          'slots': [
            {
              'inicia': '2030-01-07T16:00:00+00:00',
              'inicia_local': '2030-01-07T10:00',
            },
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

class _CuentaCupon extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);
}

const _sesion = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Vale',
  rol: 'miembro',
  modalidad: Modalidad.citas,
);

Future<void> _abrirHoja(WidgetTester tester, _Api api) async {
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
        cuentaProvider.overrideWith(_CuentaCupon.new),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => showModalBottomSheet<void>(
                context: context,
                isScrollControlled: true,
                builder: (_) => const AgendarCitaSheet(),
              ),
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

Future<void> _servicio(WidgetTester tester, String nombre) async {
  await tester.tap(find.byType(DropdownButtonFormField<OpcionCita>).first);
  await tester.pumpAndSettle();
  await tester.tap(find.textContaining(nombre).last);
  await tester.pumpAndSettle();
}

Future<void> _ponerCupon(WidgetTester tester, String codigo) async {
  await tester.ensureVisible(find.byKey(const Key('abrir-cupon')));
  await tester.tap(find.byKey(const Key('abrir-cupon')));
  await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const Key('codigo-cupon')), codigo);
  await tester.pump();
  await tester.tap(find.byKey(const Key('aplicar-cupon')));
  await tester.pumpAndSettle();
}

/// Elige día (el que ofrece el calendario) y hora, y agenda.
Future<void> _agendarCita(WidgetTester tester) async {
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

Map<String, dynamic> _enviado(_Api api) =>
    api.agendadas.single.data as Map<String, dynamic>;

void main() {
  group('qué servicios aceptan cupón', () {
    OpcionCita servicio(Map<String, dynamic> cambios) =>
        OpcionCita.desdeJson({'id': 'x', 'nombre': 'X', ...cambios});

    test('solo lo que se paga al agendar', () {
      expect(servicio({'precio_minor': 25000}).admiteCupon, isTrue);
      // Con su bono, ya pagado por adelantado o sin precio: no.
      expect(
        servicio({'precio_minor': 25000, 'con_plan': true}).admiteCupon,
        isFalse,
      );
      expect(
        servicio({'precio_minor': 25000, 'prepagados': 1}).admiteCupon,
        isFalse,
      );
      expect(servicio({'precio_minor': 0}).admiteCupon, isFalse);
      expect(servicio({}).admiteCupon, isFalse);
    });

    test('lo ya pagado por adelantado no pide anticipo (ADR 0218)', () {
      final s = servicio({
        'precio_minor': 120000,
        'anticipo_minor': 30000,
        'prepagados': 2,
      });

      expect(s.prepagados, 2);
      expect(s.anticipoAlAgendar, isNull);
      // Sin el campo (API anterior), cero.
      expect(servicio({'precio_minor': 1}).prepagados, 0);
    });
  });

  group('el repositorio', () {
    test('revisa el cupón sobre el servicio y dice cuánto descuenta', () async {
      final api = _Api(descuentoMinor: 7500);
      final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

      final revisado = await repo.revisarCupon(
        codigo: 'verano',
        servicioId: 'corte',
      );

      expect(revisado.descuentoMinor, 7500);
      expect(revisado.totalMinor, 17500);
      final pedido = api.revisados.single;
      expect(pedido.path, '/api/v1/app/demo/mi/citas/cupon');
      expect(pedido.queryParameters, {
        'codigo': 'verano',
        'oferta_id': 'corte',
      });
    });

    test('un cupón que no sirve sale como error con su motivo', () async {
      final repo = CuentaRepository(Dio()..httpClientAdapter = _Api(), 'demo');

      await expectLater(
        repo.revisarCupon(codigo: 'NADA', servicioId: 'corte'),
        throwsA(isA<DioException>()),
      );
    });

    test('manda el cupón al agendar solo si lo hay', () async {
      final api = _Api();
      final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');
      Future<Map<String, dynamic>> agendar({String? cupon}) async {
        await repo.agendarCita(
          servicioId: 'corte',
          sucursalId: 's1',
          profesionalId: 'p1',
          iniciaEnLocal: '2030-01-07T10:00',
          duracionMinutos: 30,
          cupon: cupon,
        );
        return api.agendadas.last.data as Map<String, dynamic>;
      }

      expect((await agendar()).containsKey('cupon'), isFalse);
      expect((await agendar(cupon: 'VERANO'))['cupon'], 'VERANO');
    });
  });

  group('al agendar', () {
    testWidgets('revisa el cupón, dice cuánto descuenta y lo manda', (
      tester,
    ) async {
      final api = _Api();
      await _abrirHoja(tester, api);
      // Sin servicio aún, no hay cupón.
      expect(find.byKey(const Key('abrir-cupon')), findsNothing);

      await _servicio(tester, 'Corte');
      expect(find.text('¿Tienes un cupón?'), findsOneWidget);

      await _ponerCupon(tester, 'verano');

      expect(find.text('Cupón VERANO: −\$50.00'), findsOneWidget);
      expect(api.revisados.single.queryParameters['oferta_id'], 'corte');

      await _agendarCita(tester);

      expect(_enviado(api)['oferta_id'], 'corte');
      expect(_enviado(api)['cupon'], 'verano');
      expect(find.text('¡Listo! Agendamos tu cita.'), findsOneWidget);
    });

    testWidgets('un cupón que no sirve dice por qué y no se manda', (
      tester,
    ) async {
      final api = _Api();
      await _abrirHoja(tester, api);
      await _servicio(tester, 'Corte');

      await _ponerCupon(tester, 'NADA');

      expect(find.text('El código de promoción no existe.'), findsOneWidget);
      expect(find.byKey(const Key('cupon-aplicado')), findsNothing);

      await _agendarCita(tester);

      expect(_enviado(api).containsKey('cupon'), isFalse);
    });

    testWidgets('se puede quitar antes de agendar', (tester) async {
      final api = _Api();
      await _abrirHoja(tester, api);
      await _servicio(tester, 'Corte');
      await _ponerCupon(tester, 'VERANO');

      await tester.tap(find.text('Quitar'));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('cupon-aplicado')), findsNothing);
      await _agendarCita(tester);
      expect(_enviado(api).containsKey('cupon'), isFalse);
    });

    testWidgets('al cambiar de servicio se limpia', (tester) async {
      final api = _Api();
      await _abrirHoja(tester, api);
      await _servicio(tester, 'Corte');
      await _ponerCupon(tester, 'VERANO');
      expect(find.text('Cupón VERANO: −\$50.00'), findsOneWidget);

      await _servicio(tester, 'Barba');

      // Vuelve a empezar: cerrado y sin descuento.
      expect(find.byKey(const Key('cupon-aplicado')), findsNothing);
      expect(find.text('¿Tienes un cupón?'), findsOneWidget);

      await _agendarCita(tester);

      expect(_enviado(api)['oferta_id'], 'barba');
      expect(_enviado(api).containsKey('cupon'), isFalse);
    });

    testWidgets('con su bono o ya pagado por adelantado no se ofrece', (
      tester,
    ) async {
      await _abrirHoja(tester, _Api());

      await _servicio(tester, 'Spa');
      expect(find.byKey(const Key('pago-con-bono')), findsOneWidget);
      expect(find.byKey(const Key('abrir-cupon')), findsNothing);

      await _servicio(tester, 'Color');
      expect(
        find.text('Ya lo pagaste: no pagas al agendar. Te quedan 2 veces.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('abrir-cupon')), findsNothing);
    });

    testWidgets('si al agendar el cupón ya no sirve, lo dice y no agenda', (
      tester,
    ) async {
      final api = _Api(rechazarAlAgendar: true);
      await _abrirHoja(tester, api);
      await _servicio(tester, 'Corte');
      await _ponerCupon(tester, 'VERANO');

      await _agendarCita(tester);

      expect(_enviado(api)['cupon'], 'VERANO');
      expect(
        find.text('La promoción ya no tiene usos disponibles.'),
        findsOneWidget,
      );
      // La hoja sigue abierta para quitarlo o cambiarlo.
      expect(find.text('Agendar una cita'), findsOneWidget);
    });
  });
}
