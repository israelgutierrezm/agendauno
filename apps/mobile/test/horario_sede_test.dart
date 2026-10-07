import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/formato.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/agendar_cita_sheet.dart';
import 'package:agendauno/features/cuenta/presentation/reprogramar_sheet.dart';

/// Agendar y mover una cita usan la hora de la SEDE (`inicia_local` del API), no
/// la del teléfono: una sede en Cancún vista desde la Ciudad de México muestra y
/// aparta las 10:00 de Cancún. Sin `inicia_local` (API anterior) se usa la hora
/// del teléfono, como antes. Datos sintéticos.

const _inicia = '2030-01-08T15:00:00+00:00';

/// `AAAA-MM-DDTHH:MM` de un momento (como lo arma el API para la sede).
String _local(DateTime d) => '${Formato.iso(d)}T${Formato.hora(d)}';

/// La hora del teléfono para [_inicia], y una «sede» una hora adelante (así la
/// prueba no depende de la zona de la máquina que la corre).
final _telefono = DateTime.parse(_inicia).toLocal();
final _sede = _telefono.add(const Duration(hours: 1));

/// Responde como el API según la ruta y guarda lo que se le pidió.
class _ApiHorarios implements HttpClientAdapter {
  _ApiHorarios(this.rutas);

  final Map<String, Object> rutas;
  final List<RequestOptions> peticiones = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    final ruta = rutas.keys
        .where((r) => '${options.method} ${options.path}'.endsWith(r))
        .firstOrNull;
    return ResponseBody.fromString(
      jsonEncode(ruta == null ? {'message': 'No'} : {'data': rutas[ruta]}),
      ruta == null ? 404 : 200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

class _CuentaVacia extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);
}

void main() {
  group('HorarioCita', () {
    test('con la hora de la sede: la muestra y la manda tal cual', () {
      final h = HorarioCita.desdeJson({
        'inicia': _inicia,
        'inicia_local': _local(_sede),
      });

      expect(h.hora, Formato.hora(_sede));
      expect(h.iniciaEnLocal, _local(_sede));
      expect(h.enOtraZona, isTrue);
    });

    test('sin la hora de la sede (API anterior) usa la del teléfono', () {
      final h = HorarioCita.desdeJson({'inicia': _inicia});

      expect(h.hora, Formato.hora(_telefono));
      expect(h.iniciaEnLocal, _local(_telefono));
      expect(h.enOtraZona, isFalse);
    });

    test('misma zona que el teléfono: no hay nada que avisar', () {
      final h = HorarioCita.desdeJson({
        'inicia': _inicia,
        'inicia_local': _local(_telefono),
      });

      expect(h.iniciaEnLocal, _local(_telefono));
      expect(h.enOtraZona, isFalse);
    });

    test('con segundos se recorta a AAAA-MM-DDTHH:MM; si no se entiende, se '
        'usa la del teléfono', () {
      final conSegundos = HorarioCita.desdeJson({
        'inicia': _inicia,
        'inicia_local': '2030-01-08T10:00:00',
      });
      expect(conSegundos.iniciaEnLocal, '2030-01-08T10:00');
      expect(conSegundos.hora, '10:00');

      final rara = HorarioCita.desdeJson({
        'inicia': _inicia,
        'inicia_local': '10:00',
      });
      expect(rara.iniciaEnLocal, _local(_telefono));
    });

    test('la lista descarta los horarios sin inicio', () {
      final lista = HorarioCita.deLista([
        {'inicia': _inicia, 'inicia_local': '2030-01-08T10:00'},
        {'inicia': ''},
      ]);

      expect(lista.single.iniciaLocal, '2030-01-08T10:00');
      expect(HorarioCita.deLista(null), isEmpty);
    });
  });

  test(
    'los horarios libres y los de reprogramar traen la hora de la sede',
    () async {
      final repo = CuentaRepository(
        Dio()
          ..httpClientAdapter = _ApiHorarios({
            'GET /api/v1/app/demo/mi/citas/disponibilidad': {
              'zona_horaria': 'America/Cancun',
              'slots': [
                {'inicia': _inicia, 'inicia_local': '2030-01-08T10:00'},
              ],
            },
          }),
        'demo',
      );

      final libres = await repo.horariosLibres(
        profesionalId: 'p1',
        sucursalId: 's1',
        fecha: '2030-01-08',
        duracionMinutos: 30,
      );
      expect(libres.single.iniciaEnLocal, '2030-01-08T10:00');

      final opciones = OpcionesReprogramar.desdeJson({
        'puede': true,
        'tipo': 'cita',
        'restantes': 1,
        'slots': [
          {'inicia': _inicia, 'inicia_local': '2030-01-08T10:00'},
        ],
      });
      expect(opciones.horarios.single.hora, '10:00');
    },
  );

  testWidgets('mover una cita muestra y manda la hora de la sede', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    final api = _ApiHorarios({
      'GET /api/v1/app/demo/mi/reservas/r1/reprogramar': {
        'puede': true,
        'tipo': 'cita',
        'restantes': 1,
        'zona_horaria': 'America/Cancun',
        'slots': [
          {'inicia': _inicia, 'inicia_local': _local(_sede)},
        ],
      },
      'POST /api/v1/app/demo/mi/reservas/r1/reprogramar': {'id': 'r1'},
    });
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(
            const Sesion(
              slug: 'demo',
              bearer: 't',
              nombre: 'Ana',
              rol: 'miembro',
            ),
          ),
          cuentaRepositoryProvider.overrideWithValue(repo),
          cuentaProvider.overrideWith(_CuentaVacia.new),
        ],
        child: MaterialApp(
          home: Scaffold(
            body: Builder(
              builder: (context) => TextButton(
                onPressed: () => showModalBottomSheet<void>(
                  context: context,
                  builder: (_) => const ReprogramarSheet(
                    ReservaMiembro(
                      id: 'r1',
                      estado: 'confirmada',
                      oferta: 'Corte',
                      tipo: 'cita',
                      iniciaEn: _inicia,
                    ),
                  ),
                ),
                child: const Text('Cambiar horario'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Cambiar horario'));
    await tester.pumpAndSettle();

    // La hora de la sede, no la del teléfono, y el aviso de que es otra zona.
    expect(
      find.widgetWithText(ChoiceChip, Formato.hora(_sede)),
      findsOneWidget,
    );
    expect(find.text(textoHorasDeLaSede), findsOneWidget);

    await tester.tap(find.widgetWithText(ChoiceChip, Formato.hora(_sede)));
    await tester.pump();
    await tester.tap(find.widgetWithText(FilledButton, 'Cambiar'));
    await tester.pumpAndSettle();

    final enviado =
        api.peticiones.firstWhere((p) => p.method == 'POST').data
            as Map<String, dynamic>;
    expect(enviado['inicia_en_local'], _local(_sede));
  });
}
