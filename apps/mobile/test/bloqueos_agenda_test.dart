import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/agenda/data/bloqueos.dart';
import 'package:agendauno/features/agenda/presentation/agenda_screen.dart';
import 'package:agendauno/features/agenda/presentation/bloqueos_agenda.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// Los bloqueos en la agenda de citas (TurnoUno), como en la web: se ven en el día
/// del profesional, se crean con `agenda.gestionar` (antes, la vista previa dice qué
/// citas caen ahí, que no se cancelan) y se quitan con `agenda.eliminar`. Uno que
/// viene del calendario de la persona se quita allá. Datos sintéticos.

/// Hoy a esa hora, en UTC (la sesión de prueba está en la zona UTC).
String _hoyA(int hora, [int minuto = 0]) {
  final h = DateTime.now().toUtc();
  return DateTime.utc(h.year, h.month, h.day, hora, minuto).toIso8601String();
}

/// La agenda del día con lo que da el repositorio (sus bloqueos).
class _AgendaUnaPro extends AgendaController {
  @override
  Future<AgendaDia> build() async {
    final repo = ref.watch(agendaRepositoryProvider)!;
    final dia = ref.watch(fechaAgendaProvider);
    return AgendaDia(
      sesiones: const [],
      profesionales: const [Profesional(id: 'pro1', nombre: 'Luis Peña')],
      bloqueos: await repo.bloqueos(dia, dia),
    );
  }
}

class _RepoBloqueos extends AgendaRepository {
  _RepoBloqueos({this.afectadas = const []}) : super(Dio(), 'demo');

  final List<SesionAfectada> afectadas;
  final hechas = <String>[];

  @override
  Future<List<BloqueoAgenda>> bloqueos(DateTime desde, DateTime hasta) async =>
      BloqueoAgenda.lista([
        {
          'id': 'b1',
          'ambito': 'profesional',
          'instructor_id': 'pro1',
          'nombre': 'Luis Peña',
          'desde': _hoyA(14),
          'hasta': _hoyA(15),
          'todo_el_dia': false,
          'motivo': 'Comida',
          'creado_por': 'Ana',
        },
        // De otro profesional: no se ve en el día de Luis.
        {
          'id': 'b2',
          'ambito': 'profesional',
          'instructor_id': 'pro2',
          'desde': _hoyA(9),
          'hasta': _hoyA(10),
          'motivo': 'Ausencia',
        },
        {
          'id': 'b3',
          'ambito': 'profesional',
          'instructor_id': 'pro1',
          'desde': _hoyA(18),
          'hasta': _hoyA(19),
          'motivo': 'Dentista',
          'origen': 'google',
        },
      ]);

  @override
  Future<List<SesionAfectada>> previsualizarBloqueo(NuevoBloqueo b) async {
    hechas.add('previa:${jsonEncode(b.aJson())}');
    return afectadas;
  }

  @override
  Future<({BloqueoAgenda? bloqueo, List<SesionAfectada> afectadas})>
  crearBloqueo(NuevoBloqueo b) async {
    hechas.add('crear');
    return (bloqueo: null, afectadas: afectadas);
  }

  @override
  Future<void> quitarBloqueo(String bloqueoId) async =>
      hechas.add('quitar:$bloqueoId');
}

Future<void> _abrirAgenda(
  WidgetTester tester,
  _RepoBloqueos repo, {
  List<String> permisos = const ['*'],
}) async {
  tester.view.physicalSize = const Size(420, 1400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(
          Sesion(
            slug: 'demo',
            bearer: 't',
            nombre: 'Dueña',
            rol: 'propietario',
            permisos: permisos,
            modalidad: Modalidad.citas,
            desfaseNegocioMin: 0,
          ),
        ),
        agendaRepositoryProvider.overrideWithValue(repo),
        agendaProvider.overrideWith(_AgendaUnaPro.new),
      ],
      child: const MaterialApp(home: AgendaScreen()),
    ),
  );
  await tester.pumpAndSettle();
}

class _ApiBloqueos implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    final cuerpo = options.method == 'DELETE'
        ? null
        : options.path.endsWith('/previsualizar')
        ? {
            'data': {
              'afectadas': [
                {
                  'sesion': 's1',
                  'oferta': 'Corte',
                  'inicia_en': '2026-10-26T20:00:00+00:00',
                  'reservas': 1,
                },
              ],
            },
          }
        : options.method == 'POST'
        ? {
            'data': {
              'id': 'b9',
              'ambito': 'profesional',
              'desde': '2026-10-26T20:00:00+00:00',
              'hasta': '2026-10-26T21:00:00+00:00',
            },
            'afectadas': <Object>[],
          }
        : {'data': <Object>[]};
    return ResponseBody.fromString(
      cuerpo == null ? '' : jsonEncode(cuerpo),
      cuerpo == null ? 204 : 200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test('lo que se manda: un rato de un día o días completos', () {
    final dia = DateTime(2026, 10, 26);
    expect(
      NuevoBloqueo(
        instructorId: 'pro1',
        dia: dia,
        motivo: ' Comida ',
        desdeMinuto: 14 * 60,
        hastaMinuto: 15 * 60 + 30,
      ).aJson(),
      {
        'instructor_id': 'pro1',
        'motivo': 'Comida',
        'desde_local': '2026-10-26 14:00:00',
        'hasta_local': '2026-10-26 15:30:00',
      },
    );
    expect(
      NuevoBloqueo(
        instructorId: 'pro1',
        dia: dia,
        motivo: 'Vacaciones',
        hastaDia: DateTime(2026, 10, 30),
      ).aJson(),
      {
        'instructor_id': 'pro1',
        'motivo': 'Vacaciones',
        'fecha_desde': '2026-10-26',
        'fecha_hasta': '2026-10-30',
      },
    );
  });

  test('el horario en palabras y el día que toca', () {
    DateTime pared(DateTime d) => d.toUtc();
    final rato = BloqueoAgenda.desdeJson({
      'id': 'b',
      'ambito': 'profesional',
      'desde': '2026-10-26T14:00:00+00:00',
      'hasta': '2026-10-26T15:00:00+00:00',
    });
    expect(horarioBloqueo(rato, pared), '14:00–15:00');
    expect(rato.tocaElDia(DateTime(2026, 10, 26), pared), isTrue);
    expect(rato.tocaElDia(DateTime(2026, 10, 27), pared), isFalse);
    final vacaciones = BloqueoAgenda.desdeJson({
      'id': 'v',
      'ambito': 'profesional',
      'desde': '2026-11-03T00:00:00+00:00',
      'hasta': '2026-11-06T00:00:00+00:00',
      'todo_el_dia': true,
    });
    expect(
      horarioBloqueo(vacaciones, pared),
      'Del 3 de nov. al 5 de nov. · todo el día',
    );
    expect(vacaciones.tocaElDia(DateTime(2026, 11, 5), pared), isTrue);
    expect(vacaciones.tocaElDia(DateTime(2026, 11, 6), pared), isFalse);
  });

  test('las rutas del API', () async {
    final api = _ApiBloqueos();
    final repo = AgendaRepository(Dio()..httpClientAdapter = api, 'demo');
    final nuevo = NuevoBloqueo(
      instructorId: 'pro1',
      dia: DateTime(2026, 10, 26),
      motivo: 'Comida',
      desdeMinuto: 14 * 60,
      hastaMinuto: 15 * 60,
    );

    await repo.bloqueos(DateTime(2026, 10, 26), DateTime(2026, 10, 26));
    final afectadas = await repo.previsualizarBloqueo(nuevo);
    final creado = await repo.crearBloqueo(nuevo);
    await repo.quitarBloqueo('b9');

    expect(afectadas.single.oferta, 'Corte');
    expect(creado.bloqueo?.id, 'b9');
    final [lista, previa, crear, quitar] = api.pedidos;
    expect(lista.path, '/api/v1/app/demo/bloqueos');
    expect(lista.queryParameters, {
      'desde': '2026-10-26',
      'hasta': '2026-10-26',
    });
    expect(previa.path, '/api/v1/app/demo/bloqueos/previsualizar');
    expect(crear.method, 'POST');
    expect(crear.data['desde_local'], '2026-10-26 14:00:00');
    expect(quitar.method, 'DELETE');
    expect(quitar.path, '/api/v1/app/demo/bloqueos/b9');
  });

  testWidgets('se ven en el día del profesional y se quitan con permiso', (
    tester,
  ) async {
    final repo = _RepoBloqueos();
    await _abrirAgenda(tester, repo);

    expect(find.byKey(const ValueKey('bloqueo-b1')), findsOneWidget);
    expect(find.byKey(const ValueKey('bloqueo-b2')), findsNothing);
    expect(find.textContaining('Comida · 14:00–15:00'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('bloqueo-b1')));
    await tester.pumpAndSettle();
    expect(find.text('Lo puso Ana'), findsOneWidget);
    await tester.tap(find.byKey(const Key('quitar-bloqueo')));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Quitar bloqueo'));
    await tester.pumpAndSettle();
    expect(repo.hechas, ['quitar:b1']);
    expect(find.text('Bloqueo quitado.'), findsOneWidget);
  });

  testWidgets('el del calendario de la persona se quita allá', (tester) async {
    await _abrirAgenda(tester, _RepoBloqueos());

    await tester.tap(find.byKey(const ValueKey('bloqueo-b3')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('bloqueo-de-calendario')), findsOneWidget);
    expect(find.byKey(const Key('quitar-bloqueo')), findsNothing);
  });

  testWidgets('bloquear dice antes qué citas caen ahí (no se cancelan)', (
    tester,
  ) async {
    final repo = _RepoBloqueos(
      afectadas: [
        SesionAfectada.desdeJson({
          'sesion': 's1',
          'oferta': 'Corte',
          'inicia_en': _hoyA(14, 30),
          'reservas': 1,
        }),
      ],
    );
    await _abrirAgenda(tester, repo);

    await tester.tap(find.byKey(const Key('bloquear-horario')));
    await tester.pumpAndSettle();
    expect(find.text('Bloquear el horario de Luis Peña'), findsOneWidget);
    // Sin motivo no se guarda.
    await tester.tap(find.byKey(const Key('guardar-bloqueo')));
    await tester.pumpAndSettle();
    expect(find.text('Escribe el motivo.'), findsOneWidget);
    expect(repo.hechas, isEmpty);

    await tester.tap(find.text('Comida'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('guardar-bloqueo')));
    await tester.pumpAndSettle();
    expect(find.text('Hay 1 cita en ese horario'), findsOneWidget);
    await tester.tap(find.text('Bloquear de todos modos'));
    await tester.pumpAndSettle();

    expect(repo.hechas.length, 2);
    expect(repo.hechas.first, contains('"instructor_id":"pro1"'));
    expect(repo.hechas.first, contains('"motivo":"Comida"'));
    expect(repo.hechas.last, 'crear');
    expect(
      find.text('Horario bloqueado. Queda 1 cita agendada ahí: atiéndela.'),
      findsOneWidget,
    );
  });

  testWidgets('sin permisos de gestionar ni eliminar, solo se ven', (
    tester,
  ) async {
    await _abrirAgenda(tester, _RepoBloqueos(), permisos: ['agenda.ver']);

    expect(find.byKey(const ValueKey('bloqueo-b1')), findsOneWidget);
    expect(find.byKey(const Key('bloquear-horario')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('bloqueo-b1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('quitar-bloqueo')), findsNothing);
  });
}
