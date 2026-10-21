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
import 'package:agendauno/features/cuenta/data/lugares_clase.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_widgets.dart';

/// Cambiar su lugar numerado (B06) desde el detalle de una reserva de clase, como en
/// la web: se ven los libres, se guarda el elegido y, si alguien lo tomó mientras
/// tanto, se dice y se vuelven a cargar (nunca se cambia a otro en silencio). Datos
/// sintéticos, sin red real.

/// API falso de lugares: 6 lugares, el 2 y el 4 tomados (el 2 es el suyo). Con
/// [tomado], el PUT responde que ese lugar ya no está libre.
class _ApiLugares implements HttpClientAdapter {
  _ApiLugares({this.tomado});

  final int? tomado;
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    if (options.method == 'PUT') {
      final lugar = (options.data as Map)['lugar'];
      if (lugar == tomado) {
        return _json({
          'message': 'El lugar elegido no esta disponible.',
          'code': 'SPOT_UNAVAILABLE',
        }, 422);
      }
      return _json({
        'data': {'id': 'r1', 'estado': 'confirmada', 'lugar': lugar},
      });
    }
    if (options.path.endsWith('/lugares')) {
      return _json({
        'data': {
          'total': 6,
          'ocupados': [2, 4],
          'mio': 2,
        },
      });
    }
    // Lo que se ofrece para recoger en la clase (ADR 0194): nada.
    if (options.path.endsWith('/articulos')) {
      return _json({
        'data': {'articulos': <Object>[], 'apartados': <Object>[]},
      });
    }
    // La cuenta que se vuelve a leer tras guardar.
    return options.path.endsWith('/mi/perfil')
        ? _json({'data': <String, dynamic>{}})
        : _json({'data': <Object>[]});
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(Object cuerpo, [int estado = 200]) =>
    ResponseBody.fromString(
      jsonEncode(cuerpo),
      estado,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

class _CuentaQuieta extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);
}

const _sesion = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Ana',
  rol: 'miembro',
  modalidad: Modalidad.clases,
);

ReservaMiembro _reserva({int lugares = 6, int? lugar = 2}) {
  final manana = DateTime.now().toUtc().add(const Duration(days: 1));
  return ReservaMiembro.desdeJson({
    'id': 'r1',
    'estado': 'confirmada',
    'tipo': 'clase',
    'sesion_id': 's1',
    'oferta': 'Pole Nivel 1',
    'sucursal': 'Roma Norte',
    'inicia_en': manana.toIso8601String(),
    'termina_en': manana.add(const Duration(hours: 1)).toIso8601String(),
    'lugar': lugar,
    'lugares': lugares,
  });
}

Future<void> _abrirDetalle(
  WidgetTester tester,
  _ApiLugares api,
  ReservaMiembro reserva,
) async {
  tester.view.physicalSize = const Size(390, 1400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(_sesion),
        cuentaRepositoryProvider.overrideWithValue(
          CuentaRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
        cuentaProvider.overrideWith(_CuentaQuieta.new),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: Consumer(
            builder: (context, ref, _) => TextButton(
              onPressed: () => mostrarDetalleReserva(context, ref, reserva),
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

void main() {
  test('lee los lugares: cuántos, los tomados y el suyo', () {
    final l = LugaresDeClase.desdeJson({
      'total': 6,
      'ocupados': [2, 4],
      'mio': 2,
    })!;

    expect(l.total, 6);
    expect(l.libre(2), isTrue, reason: 'el suyo se puede conservar');
    expect(l.libre(4), isFalse);
    expect(l.libre(5), isTrue);
    // Sin lugares numerados, el API manda null.
    expect(LugaresDeClase.desdeJson(null), isNull);
  });

  test('la reserva dice cuántos lugares numera su clase', () {
    expect(_reserva().lugares, 6);
    expect(
      ReservaMiembro.desdeJson({
        'id': 'r2',
        'estado': 'confirmada',
        'tipo': 'clase',
      }).lugares,
      0,
    );
  });

  test('pide los lugares de la clase y cambia el de su reserva', () async {
    final api = _ApiLugares();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    final lugares = await repo.lugaresDeClase('s1');
    await repo.cambiarLugar('r1', 5);

    expect(lugares?.mio, 2);
    expect(api.pedidos[0].method, 'GET');
    expect(api.pedidos[0].path, '/api/v1/app/demo/mi/sesiones/s1/lugares');
    expect(api.pedidos[1].method, 'PUT');
    expect(api.pedidos[1].path, '/api/v1/app/demo/mi/reservas/r1/lugar');
    expect(api.pedidos[1].data, {'lugar': 5});
  });

  testWidgets('desde el detalle cambia su lugar por uno libre', (tester) async {
    final api = _ApiLugares();
    await _abrirDetalle(tester, api, _reserva());

    expect(find.text('Tu lugar: 2'), findsOneWidget);
    await tester.tap(find.byKey(const Key('cambiar-lugar')));
    await tester.pumpAndSettle();

    expect(find.text('Cambiar mi lugar'), findsOneWidget);
    // El 4 está tomado: no se puede elegir.
    final cuatro = tester.widget<ChoiceChip>(find.byKey(const Key('lugar-4')));
    expect(cuatro.onSelected, isNull);

    await tester.tap(find.byKey(const Key('lugar-5')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Quedarme con el 5'));
    await tester.pumpAndSettle();

    final put = api.pedidos.where((p) => p.method == 'PUT').single;
    expect(put.data, {'lugar': 5});
    expect(find.text('Listo: tu lugar es el 5.'), findsOneWidget);
    expect(find.text('Cambiar mi lugar'), findsNothing);
  });

  testWidgets('si alguien lo tomó, lo dice y vuelve a cargar los libres', (
    tester,
  ) async {
    final api = _ApiLugares(tomado: 6);
    await _abrirDetalle(tester, api, _reserva());
    await tester.tap(find.byKey(const Key('cambiar-lugar')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('lugar-6')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Quedarme con el 6'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('lugar-error')), findsOneWidget);
    expect(
      api.pedidos.where((p) => p.path.endsWith('/lugares')).length,
      2,
      reason: 'se vuelven a pedir los libres',
    );
    // Sigue en la hoja, con su lugar de antes.
    expect(find.text('Cambiar mi lugar'), findsOneWidget);
    expect(find.text('Elige un lugar libre'), findsOneWidget);
  });

  testWidgets('una clase sin lugares numerados no lo ofrece', (tester) async {
    await _abrirDetalle(tester, _ApiLugares(), _reserva(lugares: 0));

    expect(find.byKey(const Key('cambiar-lugar')), findsNothing);
  });

  testWidgets('sin lugar todavía, ofrece elegirlo', (tester) async {
    await _abrirDetalle(tester, _ApiLugares(), _reserva(lugar: null));

    expect(find.text('Elegir mi lugar'), findsOneWidget);
  });
}
