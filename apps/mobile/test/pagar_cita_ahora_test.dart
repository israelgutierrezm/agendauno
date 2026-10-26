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

/// TurnoUno, el cliente: al agendar una cita que se paga para apartarla (o con
/// anticipo), el aviso trae «Pagar ahora» si el negocio cobra en línea; sin cobro en
/// línea, solo el aviso. Datos sintéticos, sin red real.

const _opcionesCita = {
  'servicios': [
    {
      'id': 'corte',
      'nombre': 'Corte',
      'duracion_minutos': 30,
      'precio_minor': 25000,
      'moneda': 'MXN',
    },
  ],
  'sucursales': [
    {'id': 's1', 'nombre': 'Centro'},
  ],
  'instructores': [
    {'id': 'p1', 'nombre': 'Ana'},
  ],
};

class _ApiCita implements HttpClientAdapter {
  final cobros = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final ruta = options.path;
    Object? data;
    if (options.method == 'POST' && ruta.endsWith('/mi/citas')) {
      data = {'estado': 'pendiente_pago', 'orden_id': 'o9'};
    } else if (ruta.endsWith('/mi/ordenes/o9/cobrar')) {
      cobros.add(options);
      // Sin página de la pasarela: queda «en proceso».
      data = {'checkout': null};
    } else if (ruta.endsWith('/mi/citas/opciones')) {
      data = _opcionesCita;
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

class _CuentaCobra extends CuentaController {
  _CuentaCobra(this.pagoEnLinea);

  final bool pagoEnLinea;

  @override
  Future<MiCuenta> build() async => MiCuenta(
    derechos: const [],
    reservas: const [],
    clases: const [],
    pagoEnLinea: pagoEnLinea,
  );

  // La cuenta no cambia al recargar (no hay /mi/perfil en esta prueba).
  @override
  Future<void> recargar() async {}
}

Future<void> _agendarCita(
  WidgetTester tester,
  _ApiCita api, {
  required bool pagoEnLinea,
}) async {
  tester.view.physicalSize = const Size(390, 1800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(
          const Sesion(
            slug: 'demo',
            bearer: 't',
            nombre: 'Vale',
            rol: 'miembro',
            modalidad: Modalidad.citas,
          ),
        ),
        cuentaRepositoryProvider.overrideWithValue(
          CuentaRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
        cuentaProvider.overrideWith(() => _CuentaCobra(pagoEnLinea)),
      ],
      child: MaterialApp(
        // Como la cuenta del cliente: la pantalla tiene su cuenta cargada.
        home: Scaffold(
          body: Consumer(
            builder: (context, ref, _) => ref.watch(cuentaProvider).isLoading
                ? const SizedBox.shrink()
                : TextButton(
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
  await tester.pumpAndSettle();
  await tester.tap(find.text('Abrir'));
  await tester.pumpAndSettle();
  await tester.tap(find.byType(DropdownButtonFormField<OpcionCita>).first);
  await tester.pumpAndSettle();
  await tester.tap(find.text('Corte').last);
  await tester.pumpAndSettle();
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
  test('agendar dice la orden que hay que pagar', () async {
    final api = _ApiCita();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    final cita = await repo.agendarCita(
      servicioId: 'corte',
      sucursalId: 's1',
      profesionalId: 'p1',
      iniciaEnLocal: '2030-01-07T10:00',
      duracionMinutos: 30,
    );

    expect(cita.estado, 'pendiente_pago');
    expect(cita.ordenId, 'o9');
  });

  testWidgets('con cobro en línea, el aviso trae «Pagar ahora»', (
    tester,
  ) async {
    final api = _ApiCita();
    await _agendarCita(tester, api, pagoEnLinea: true);

    expect(
      find.textContaining('Tu cita quedó apartada. Págala para confirmarla.'),
      findsOneWidget,
    );
    await tester.tap(find.text('Pagar ahora'));
    await tester.pumpAndSettle();

    expect(api.cobros, hasLength(1));
    expect(find.textContaining('Tu pago está en proceso'), findsOneWidget);
  });

  testWidgets('sin cobro en línea, solo el aviso', (tester) async {
    await _agendarCita(tester, _ApiCita(), pagoEnLinea: false);

    expect(find.textContaining('Tu cita quedó apartada'), findsOneWidget);
    expect(find.text('Pagar ahora'), findsNothing);
  });
}
