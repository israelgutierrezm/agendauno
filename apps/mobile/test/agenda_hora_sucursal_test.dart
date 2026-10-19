import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/formato.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/agenda/presentation/agenda_screen.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/instructor/application/mis_clases_controller.dart';

/// La agenda del personal muestra SIEMPRE el día y la hora de la sucursal
/// (`inicia_local`/`termina_local` del API), esté donde esté el teléfono; el
/// instante real (`inicia_en`) solo ordena y se compara con «ahora». Si el
/// teléfono está en otra zona, se avisa («Hora de Roma Norte»). Sin los campos
/// nuevos (API anterior), la hora del teléfono, como antes. Datos sintéticos.
///
/// Las pruebas no dependen de la zona de la máquina: la «sucursal» se pone dos
/// horas detrás del teléfono, así las 23:30 de la sucursal ya son el día
/// siguiente en el teléfono.

/// Desfase (min) del teléfono, es decir, de la máquina que corre las pruebas.
final _telefono = DateTime.utc(2026, 10, 3).toLocal().timeZoneOffset.inMinutes;

/// Una sucursal dos horas detrás del teléfono.
final _sucursal = _telefono - 120;

/// El instante en que en la sucursal (con ese [desfase]; por omisión, dos horas
/// detrás del teléfono) es el [dia] de octubre a esa hora.
DateTime _instante(int dia, int hora, int minuto, {int? desfase}) =>
    DateTime.utc(
      2026,
      10,
      dia,
      hora,
      minuto,
    ).subtract(Duration(minutes: desfase ?? _sucursal));

String _dos(int n) => n.toString().padLeft(2, '0');

/// `AAAA-MM-DDTHH:MM:SS` sin zona, como lo manda el API.
String _pared(int dia, int hora, int minuto, {int mes = 10}) =>
    '2026-${_dos(mes)}-${_dos(dia)}T${_dos(hora)}:${_dos(minuto)}:00';

/// «2026-10-02 23:30» de una fecha (sus campos, sin convertir).
String _texto(DateTime d) => '${Formato.iso(d)} ${Formato.hora(d)}';

/// Una clase como la manda GET /sesiones; [local] agrega la hora de la sucursal.
Map<String, dynamic> _sesion({
  String id = 's1',
  required int dia,
  required int hora,
  required int minuto,
  int duracion = 60,
  bool local = true,
  int? desfase,
  String? sucursal = 'Roma Norte',
}) {
  final zona = desfase ?? _sucursal;
  final inicia = _instante(dia, hora, minuto, desfase: zona);
  final termina = inicia.add(Duration(minutes: duracion));
  final paredFin = termina.add(Duration(minutes: zona));
  return {
    'id': id,
    'tipo': 'clase',
    'oferta': 'Pole Nivel 1',
    'oferta_id': 'o1',
    'instructor': 'Alma Ruiz',
    'instructor_id': 'p1',
    'sala': null,
    'sucursal': sucursal,
    'inicia_en': inicia.toIso8601String(),
    'termina_en': termina.toIso8601String(),
    'zona_horaria': 'America/Mexico_City',
    if (local) ...{
      'inicia_local': _pared(dia, hora, minuto),
      'termina_local': _pared(
        paredFin.day,
        paredFin.hour,
        paredFin.minute,
        mes: paredFin.month,
      ),
      'utc_offset_min': zona,
    },
    'capacidad': 10,
    'ocupados': 2,
    'en_espera': 0,
    'estado': 'programada',
    'clase': {
      'capacidad': 10,
      'ocupados': 2,
      'libres': 8,
      'en_espera': 0,
      'lugares': 0,
      'de_pago': false,
      'precio_minor': null,
    },
    'ocupacion': null,
    'cita': null,
  };
}

/// Responde GET /sesiones con [sesiones] y guarda lo que se le pidió.
class _ApiSesiones implements HttpClientAdapter {
  _ApiSesiones(this.sesiones);

  final List<Map<String, dynamic>> sesiones;
  final List<RequestOptions> peticiones = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    return ResponseBody.fromString(
      jsonEncode({'data': sesiones}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

class _Agenda extends AgendaController {
  _Agenda(this._sesiones);

  final List<Map<String, dynamic>> _sesiones;

  @override
  Future<AgendaDia> build() async => AgendaDia(
    sesiones: _sesiones.map(SesionAgenda.desdeJson).toList(),
    profesionales: const [Profesional(id: 'p1', nombre: 'Alma Ruiz')],
  );
}

const _recepcion = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Recepción',
  rol: 'propietario',
  permisos: ['*'],
  modalidad: Modalidad.clases,
);

void main() {
  group('SesionAgenda.desdeJson', () {
    test('con la hora de la sucursal: la guarda tal cual, con su desfase', () {
      final s = SesionAgenda.desdeJson(_sesion(dia: 2, hora: 23, minuto: 30));

      expect(_texto(s.iniciaLocal), '2026-10-02 23:30');
      expect(_texto(s.terminaLocal), '2026-10-03 00:30');
      expect(s.desfaseMin, _sucursal);
      // El instante real se conserva (para ordenar y comparar con «ahora»).
      expect(s.iniciaEn.isAtSameMomentAs(_instante(2, 23, 30)), isTrue);
      expect(s.duracionMin, 60);
    });

    test('sin los campos nuevos (API anterior): la hora del teléfono', () {
      final s = SesionAgenda.desdeJson(
        _sesion(dia: 2, hora: 23, minuto: 30, local: false),
      );
      final telefono = _instante(2, 23, 30).toLocal();

      expect(_texto(s.iniciaLocal), _texto(telefono));
      expect(s.desfaseMin, isNull);
      expect(s.enOtraZona(_telefono), isFalse);
      expect(s.enOtraZona(_telefono + 60), isFalse);
      expect(s.etiquetaHora(), isNull);
    });

    test(
      'sin desfase se deduce de la hora local; sin hora local, se calcula',
      () {
        final soloLocal = SesionAgenda.desdeJson(
          _sesion(dia: 2, hora: 23, minuto: 30)..remove('utc_offset_min'),
        );
        final soloDesfase = SesionAgenda.desdeJson(
          _sesion(dia: 2, hora: 23, minuto: 30)
            ..remove('inicia_local')
            ..remove('termina_local'),
        );

        expect(soloLocal.desfaseMin, _sucursal);
        expect(_texto(soloDesfase.iniciaLocal), '2026-10-02 23:30');
        expect(_texto(soloDesfase.terminaLocal), '2026-10-03 00:30');
      },
    );

    test('una hora local que no se entiende no rompe: se usa el desfase', () {
      final s = SesionAgenda.desdeJson({
        ..._sesion(dia: 2, hora: 23, minuto: 30),
        'inicia_local': 'mañana',
      });

      expect(_texto(s.iniciaLocal), '2026-10-02 23:30');
    });
  });

  test('las 23:30 de la sucursal siguen en su día y su hora aunque el '
      'teléfono ya esté en el día siguiente', () {
    final s = SesionAgenda.desdeJson(_sesion(dia: 2, hora: 23, minuto: 30));

    // En el teléfono ya es el 3 a la 01:30…
    expect(_texto(s.iniciaEn.toLocal()), '2026-10-03 01:30');
    // …pero se muestra y se agrupa como el 2 a las 23:30 de la sucursal.
    expect(Formato.hora(s.iniciaLocal), '23:30');
    expect(s.esDelDia(DateTime(2026, 10, 2)), isTrue);
    expect(s.esDelDia(DateTime(2026, 10, 3)), isFalse);
    // Lo que se mide con el reloj sale en la hora de la sucursal.
    final ahora = _instante(2, 22, 0);
    expect(_texto(s.enSucursal(ahora)), '2026-10-02 22:00');
    expect(s.hoyEnSucursal(ahora), DateTime(2026, 10, 2));
  });

  group('etiqueta de la hora de la sucursal', () {
    test('solo cuando el desfase del teléfono es otro', () {
      final s = SesionAgenda.desdeJson(_sesion(dia: 2, hora: 10, minuto: 0));

      expect(s.enOtraZona(_sucursal), isFalse);
      expect(s.etiquetaHora(_sucursal), isNull);
      expect(s.enOtraZona(_sucursal + 60), isTrue);
      expect(s.etiquetaHora(_sucursal + 60), 'Hora de Roma Norte');
      // Por omisión, el desfase del teléfono (aquí, dos horas adelante).
      expect(s.etiquetaHora(), 'Hora de Roma Norte');
    });

    test('sin nombre de sucursal, o con varias, lo dice en general', () {
      final sinNombre = SesionAgenda.desdeJson(
        _sesion(dia: 2, hora: 10, minuto: 0, sucursal: null),
      );
      final otra = SesionAgenda.desdeJson(
        _sesion(id: 's2', dia: 2, hora: 12, minuto: 0, sucursal: 'Centro'),
      );
      final roma = SesionAgenda.desdeJson(_sesion(dia: 2, hora: 10, minuto: 0));

      expect(
        etiquetaHoraSucursal([sinNombre], desfaseTelefonoMin: _telefono),
        'Hora de la sucursal',
      );
      expect(
        etiquetaHoraSucursal([roma, otra], desfaseTelefonoMin: _telefono),
        'Hora de cada sucursal',
      );
      expect(
        etiquetaHoraSucursal([roma, otra], desfaseTelefonoMin: _sucursal),
        isNull,
      );
      expect(etiquetaHoraSucursal(const []), isNull);
    });

    test('una sucursal en la zona del teléfono no se etiqueta', () {
      final misma = SesionAgenda.desdeJson(
        _sesion(dia: 2, hora: 10, minuto: 0, desfase: _telefono),
      );

      expect(misma.enOtraZona(), isFalse);
      expect(etiquetaHoraSucursal([misma]), isNull);
    });
  });

  group('AgendaRepository.sesiones', () {
    final sesiones = [
      // El 1 a las 23:30 en la sucursal (en el teléfono, el 2 a la 01:30).
      _sesion(id: 'ayer', dia: 1, hora: 23, minuto: 30),
      // El 2 a las 23:30 en la sucursal (en el teléfono, el 3 a la 01:30).
      _sesion(id: 'noche', dia: 2, hora: 23, minuto: 30),
      _sesion(id: 'manana', dia: 2, hora: 9, minuto: 0),
      // El 3 a las 00:30 en la sucursal.
      _sesion(id: 'madrugada', dia: 3, hora: 0, minuto: 30),
    ];

    Future<(List<SesionAgenda>, _ApiSesiones)> pedir(DateTime dia) async {
      final api = _ApiSesiones(sesiones);
      final dio = Dio()..httpClientAdapter = api;
      final lista = await AgendaRepository(dio, 'demo').sesionesDelDia(dia);
      return (lista, api);
    }

    test('filtra por el día de la sucursal y ordena por el instante', () async {
      final (dos, api) = await pedir(DateTime(2026, 10, 2));

      expect(dos.map((s) => s.id), ['manana', 'noche']);
      expect(api.peticiones.single.queryParameters, {
        'desde': '2026-10-02',
        'hasta': '2026-10-02',
      });

      final (tres, _) = await pedir(DateTime(2026, 10, 3));
      expect(tres.map((s) => s.id), ['madrugada']);
    });

    test(
      'sin hora de la sucursal (API anterior), por el día del teléfono',
      () async {
        final api = _ApiSesiones([
          _sesion(id: 'noche', dia: 2, hora: 23, minuto: 30, local: false),
        ]);
        final dio = Dio()..httpClientAdapter = api;
        final repo = AgendaRepository(dio, 'demo');

        // En el teléfono es el 3 a la 01:30.
        expect(await repo.sesionesDelDia(DateTime(2026, 10, 2)), isEmpty);
        expect(
          (await repo.sesionesDelDia(DateTime(2026, 10, 3))).single.id,
          'noche',
        );
      },
    );
  });

  test('«hoy» y los próximos días del instructor son los de la sucursal', () {
    final lista = [
      _sesion(id: 'ayer', dia: 1, hora: 23, minuto: 30),
      _sesion(id: 'hoy', dia: 2, hora: 23, minuto: 30),
      _sesion(id: 'septimo', dia: 8, hora: 23, minuto: 30),
      _sesion(id: 'octavo', dia: 9, hora: 0, minuto: 30),
    ].map(SesionAgenda.desdeJson).toList();
    // Las 22:00 del 2 en la sucursal: en el teléfono ya es el 3 a medianoche.
    final ahora = _instante(2, 22, 0);
    expect(ahora.toLocal().day, 3);

    expect(deLosProximosDias(lista, ahora).map((s) => s.id), [
      'hoy',
      'septimo',
    ]);
    final dia = AgendaDia(sesiones: lista, profesionales: const []);
    expect(dia.hoyEnSucursal(ahora), DateTime(2026, 10, 2));
  });

  group('AgendaScreen', () {
    Future<void> abrir(
      WidgetTester tester,
      List<Map<String, dynamic>> sesiones,
    ) async {
      tester.view.physicalSize = const Size(600, 1000);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            sesionInicialProvider.overrideWithValue(_recepcion),
            agendaProvider.overrideWith(() => _Agenda(sesiones)),
          ],
          child: const MaterialApp(home: AgendaScreen()),
        ),
      );
      await tester.pump();
      await tester.pump();
    }

    testWidgets('muestra la hora de la sucursal y lo avisa si el teléfono '
        'está en otra zona', (tester) async {
      await abrir(tester, [_sesion(dia: 2, hora: 23, minuto: 30)]);

      expect(find.text('23:30'), findsOneWidget);
      expect(find.text('01:30'), findsNothing);
      expect(find.byKey(const Key('hora-de-la-sucursal')), findsOneWidget);
      expect(find.text('Hora de Roma Norte'), findsOneWidget);
    });

    testWidgets('sin aviso si la sucursal está en la zona del teléfono', (
      tester,
    ) async {
      await abrir(tester, [
        _sesion(dia: 2, hora: 23, minuto: 30, desfase: _telefono),
      ]);

      expect(find.text('23:30'), findsOneWidget);
      expect(find.byKey(const Key('hora-de-la-sucursal')), findsNothing);
    });
  });
}
