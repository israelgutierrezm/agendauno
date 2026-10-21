import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/formato.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/agenda/presentation/agenda_screen.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart'
    show HorarioCita;

/// La operación diaria de citas desde la app del equipo, como en la web: agendar
/// una cita a un cliente (buscarlo o darlo de alta al vuelo), reprogramarla a otra
/// hora o con otra persona y, sin permiso, no ofrecerlo. Se manda lo mismo que la
/// web a los mismos endpoints del equipo. Datos sintéticos, sin red.

DateTime get _hoy {
  final a = DateTime.now();
  return DateTime(a.year, a.month, a.day);
}

String _ymd(DateTime d) => AgendaRepository.ymd(d);

/// Una cita de hoy a la [hora] (de la sucursal) para la agenda.
Map<String, dynamic> _citaJson({
  required String id,
  required String instructorId,
  required String instructor,
  required String cliente,
  required int hora,
  int minuto = 0,
}) {
  final inicio = DateTime(_hoy.year, _hoy.month, _hoy.day, hora, minuto);
  final local =
      '${_ymd(inicio)}T${Formato.hora(inicio)}:00'; // sin zona, como el API
  return {
    'id': id,
    'tipo': 'cita',
    'oferta': 'Corte',
    'oferta_id': 'o1',
    'instructor': instructor,
    'instructor_id': instructorId,
    'sucursal': 'Centro',
    'sucursal_id': 's1',
    'inicia_en': inicio.toUtc().toIso8601String(),
    'termina_en': inicio
        .add(const Duration(hours: 1))
        .toUtc()
        .toIso8601String(),
    'inicia_local': local,
    'capacidad': 1,
    'ocupados': 1,
    'en_espera': 0,
    'estado': 'programada',
    'cita': {
      'reserva_id': 'r-$id',
      'cliente': cliente,
      'estado': 'confirmada',
      'estado_atencion': 'confirmada',
    },
  };
}

DioException _rechazo(String mensaje) {
  final pedido = RequestOptions(path: '/agenda/citas');
  return DioException(
    requestOptions: pedido,
    response: Response(
      requestOptions: pedido,
      statusCode: 422,
      data: {'message': mensaje, 'code': 'SESSION_NOT_BOOKABLE'},
    ),
  );
}

/// El API del equipo, en memoria: anota lo que se le pidió.
class _Repo extends AgendaRepository {
  _Repo([List<Map<String, dynamic>>? sesiones])
    : _sesiones = [...?sesiones],
      super(Dio(), 'demo');

  final List<Map<String, dynamic>> _sesiones;
  var cargas = 0;
  final horas = <Map<String, Object?>>[];
  final busquedas = <String>[];
  final altas = <Map<String, Object?>>[];
  final agendadas = <Map<String, dynamic>>[];
  final movidas = <Map<String, Object?>>[];

  /// Si no es null, agendar responde este rechazo (p. ej. el hueco se ocupó).
  DioException? rechazarAgenda;
  final _nombres = <String, String>{'a1': 'Ana López'};

  @override
  Future<List<SesionAgenda>> sesiones(DateTime desde, DateTime hasta) async {
    cargas++;
    return _sesiones.map(SesionAgenda.desdeJson).toList();
  }

  @override
  Future<List<Profesional>> profesionales() async => const [
    Profesional(id: 'p1', nombre: 'Alma Ruiz'),
    Profesional(id: 'p2', nombre: 'Beto Ramírez'),
  ];

  @override
  Future<List<ServicioCita>> serviciosParaCita() async => const [
    ServicioCita(
      id: 'o1',
      nombre: 'Corte',
      precioMinor: 15000,
      politicaReserva: 'pago',
      duracionMinutos: 30,
    ),
    ServicioCita(id: 'o2', nombre: 'Barba'),
  ];

  @override
  Future<List<SucursalAgenda>> sucursales() async => const [
    SucursalAgenda(id: 's1', nombre: 'Centro'),
  ];

  @override
  Future<List<HorarioCita>> horariosLibres({
    required String instructorId,
    required String sucursalId,
    required DateTime fecha,
    String? ofertaId,
    int? duracionMinutos,
    String? excluirReserva,
  }) async {
    horas.add({
      'instructor_id': instructorId,
      'sucursal_id': sucursalId,
      'fecha': _ymd(fecha),
      'oferta_id': ofertaId,
      'excluir_reserva': ?excluirReserva,
    });
    HorarioCita a(int h, int m) {
      final inicio = DateTime(fecha.year, fecha.month, fecha.day, h, m);
      return HorarioCita(
        inicia: inicio.toUtc().toIso8601String(),
        iniciaLocal: '${_ymd(inicio)}T${Formato.hora(inicio)}',
      );
    }

    return [a(10, 0), a(10, 30), a(11, 0)];
  }

  @override
  Future<List<ClienteAgenda>> buscarClientes(String texto) async {
    busquedas.add(texto);
    return texto.toLowerCase().contains('ana')
        ? const [
            ClienteAgenda(id: 'a1', nombre: 'Ana López', detalle: 'ana@x.mx'),
          ]
        : const [];
  }

  @override
  Future<ClienteAgenda> crearCliente({
    required String nombre,
    String? celular,
    String? email,
    String? sucursalId,
    bool aceptaWhatsApp = false,
  }) async {
    altas.add({
      'nombre': nombre,
      'celular': celular,
      'email': email,
      'sucursal_id': sucursalId,
      if (aceptaWhatsApp) 'acepta_whatsapp': true,
    });
    final id = 'nuevo-${altas.length}';
    _nombres[id] = nombre;
    return ClienteAgenda(id: id, nombre: nombre, detalle: celular);
  }

  @override
  Future<SesionAgenda> agendarCita(NuevaCita cita) async {
    if (rechazarAgenda case final error?) {
      throw error;
    }
    agendadas.add(cita.aJson());
    final json = _citaJson(
      id: 'nueva-${agendadas.length}',
      instructorId: cita.instructorId,
      instructor: cita.instructorId == 'p1' ? 'Alma Ruiz' : 'Beto Ramírez',
      cliente: _nombres[cita.personaId] ?? '—',
      hora: cita.inicia.hour,
      minuto: cita.inicia.minute,
    );
    _sesiones.add(json);
    return SesionAgenda.desdeJson(json);
  }

  @override
  Future<CitaMovida> reprogramarCita(
    String reservaId, {
    required DateTime inicia,
    String? instructorId,
  }) async {
    movidas.add({
      'reserva': reservaId,
      'inicia_en_local': horaLocalParaApi(inicia),
      'instructor_id': instructorId,
    });
    return const CitaMovida();
  }
}

const _permisosRecepcion = [
  'agenda.ver',
  'reservas.ver',
  'reservas.gestionar',
  'asistencia.marcar',
  'miembros.ver',
  'miembros.gestionar',
  'catalogo.ver',
  'sucursales.ver',
];

Sesion _sesion({
  String rol = 'recepcionista',
  List<String> permisos = _permisosRecepcion,
  Modalidad modalidad = Modalidad.citas,
  bool whatsappClientes = false,
}) => Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Recepción',
  rol: rol,
  permisos: permisos,
  modalidad: modalidad,
  whatsappClientes: whatsappClientes,
  terminologia: const Terminologia(
    sesion: 'Cita',
    miembro: 'Cliente',
    instructor: 'Profesional',
  ),
);

Future<void> _abrirAgenda(
  WidgetTester tester,
  _Repo repo, {
  Sesion? sesion,
}) async {
  tester.view.physicalSize = const Size(800, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(sesion ?? _sesion()),
        agendaRepositoryProvider.overrideWithValue(repo),
      ],
      child: const MaterialApp(home: AgendaScreen()),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _tocar(WidgetTester tester, Finder f) async {
  await tester.ensureVisible(f);
  await tester.pumpAndSettle();
  await tester.tap(f);
  await tester.pumpAndSettle();
}

Future<void> _buscarCliente(WidgetTester tester, String texto) async {
  await tester.enterText(find.byKey(const Key('buscar-cliente')), texto);
  // El buscador espera a que se deje de escribir.
  await tester.pump(const Duration(milliseconds: 300));
  await tester.pumpAndSettle();
}

/// La fila de la confirmación (qué → cuál).
Finder _fila(String cual) => find.descendant(
  of: find.byKey(const Key('confirmar-cita')),
  matching: find.text(cual),
);

void main() {
  test(
    'la sesión sabe si el negocio manda avisos por WhatsApp y lo guarda',
    () {
      final sesion = Sesion.desdeJson(
        'demo',
        'token',
        {'nombre': 'Recepción', 'rol': 'recepcionista'},
        {'whatsapp_clientes': true},
      );
      expect(sesion.whatsappClientes, isTrue);
      expect(Sesion.desdeAlmacen(sesion.aJson())?.whatsappClientes, isTrue);
      // Un API anterior no lo manda: no se pregunta.
      expect(
        Sesion.desdeJson('demo', 'token', {
          'rol': 'recepcionista',
        }).whatsappClientes,
        isFalse,
      );
    },
  );

  group('agendar desde la app del equipo', () {
    testWidgets(
      'busca al cliente, elige un hueco, confirma todo y la agenda se recarga',
      (tester) async {
        final repo = _Repo();
        await _abrirAgenda(tester, repo);
        expect(find.text('Sin citas este día.'), findsOneWidget);
        final cargasAntes = repo.cargas;

        await _tocar(tester, find.byKey(const Key('nueva-cita')));
        // Los huecos libres de quien se está viendo, en su sucursal y ese día.
        expect(repo.horas.last, {
          'instructor_id': 'p1',
          'sucursal_id': 's1',
          'fecha': _ymd(_hoy),
          'oferta_id': 'o1',
        });
        expect(
          find.text('Queda confirmada y se cobra en caja: \$150.00.'),
          findsOneWidget,
        );

        await _buscarCliente(tester, 'Ana');
        expect(repo.busquedas, ['Ana']);
        await _tocar(tester, find.byKey(const Key('cliente-a1')));
        expect(find.byKey(const Key('cliente-elegido')), findsOneWidget);

        await _tocar(tester, find.byKey(const Key('hora-10:30')));
        await _tocar(tester, find.byKey(const Key('agendar-cita')));

        // Se confirma con servicio, profesional, hora, cliente y precio a la vista.
        final cuando = Formato.diaHora(
          DateTime.utc(_hoy.year, _hoy.month, _hoy.day, 10, 30),
        );
        expect(_fila('Ana López'), findsOneWidget);
        expect(_fila('Corte'), findsOneWidget);
        expect(_fila('Alma Ruiz'), findsOneWidget);
        expect(_fila('$cuando · 30 min'), findsOneWidget);
        expect(_fila('\$150.00, se cobra en caja'), findsOneWidget);

        // Volver no agenda.
        await _tocar(tester, find.text('Volver'));
        expect(repo.agendadas, isEmpty);

        await _tocar(tester, find.byKey(const Key('agendar-cita')));
        await _tocar(tester, find.widgetWithText(FilledButton, 'Agendar'));

        // Lo mismo que manda la web a POST /agenda/citas.
        expect(repo.agendadas, [
          {
            'persona_id': 'a1',
            'oferta_id': 'o1',
            'sucursal_id': 's1',
            'instructor_id': 'p1',
            'inicia_en_local': '${_ymd(_hoy)} 10:30:00',
            'duracion_minutos': 30,
          },
        ]);
        expect(repo.altas, isEmpty);
        // La hoja se cierra, la agenda se recargó y la cita ya está en ella.
        expect(find.byKey(const Key('agendar-cita')), findsNothing);
        expect(repo.cargas, greaterThan(cargasAntes));
        expect(
          find.text('Cita agendada: Ana López a las 10:30.'),
          findsOneWidget,
        );
        expect(find.text('Ana López'), findsOneWidget);
      },
    );

    testWidgets('da de alta al cliente al vuelo y le agenda con su id', (
      tester,
    ) async {
      final repo = _Repo();
      await _abrirAgenda(tester, repo);
      await _tocar(tester, find.byKey(const Key('nueva-cita')));

      await _buscarCliente(tester, 'Pedro Páramo');
      expect(find.text('Nadie coincide con «Pedro Páramo».'), findsOneWidget);
      await _tocar(tester, find.byKey(const Key('cliente-nuevo')));

      // Lo que se buscó ya va en su nombre.
      expect(find.byKey(const Key('alta-rapida')), findsOneWidget);
      expect(find.text('Pedro Páramo'), findsOneWidget);
      await tester.enterText(
        find.byKey(const Key('nuevo-celular')),
        '55 1111 2222',
      );
      await tester.pump();
      // El negocio no manda avisos por WhatsApp: no se pregunta.
      expect(find.byKey(const Key('acepta-whatsapp')), findsNothing);
      await _tocar(tester, find.byKey(const Key('hora-10:00')));
      await _tocar(tester, find.byKey(const Key('agendar-cita')));

      expect(_fila('Pedro Páramo (se da de alta)'), findsOneWidget);
      await _tocar(tester, find.widgetWithText(FilledButton, 'Agendar'));

      // Como la web: POST /miembros y luego la cita con el id que devolvió.
      expect(repo.altas, [
        {
          'nombre': 'Pedro Páramo',
          'celular': '55 1111 2222',
          'email': null,
          'sucursal_id': 's1',
        },
      ]);
      expect(repo.agendadas.single['persona_id'], 'nuevo-1');
      expect(
        repo.agendadas.single['inicia_en_local'],
        '${_ymd(_hoy)} 10:00:00',
      );
    });

    testWidgets('con avisos por WhatsApp, el alta pregunta si los acepta', (
      tester,
    ) async {
      final repo = _Repo();
      await _abrirAgenda(tester, repo, sesion: _sesion(whatsappClientes: true));
      await _tocar(tester, find.byKey(const Key('nueva-cita')));
      await _buscarCliente(tester, 'Pedro Páramo');
      await _tocar(tester, find.byKey(const Key('cliente-nuevo')));

      // Sin celular no hay a dónde mandarlos.
      expect(find.byKey(const Key('acepta-whatsapp')), findsNothing);
      await tester.enterText(
        find.byKey(const Key('nuevo-celular')),
        '55 1111 2222',
      );
      await tester.pump();
      await _tocar(tester, find.byKey(const Key('acepta-whatsapp')));
      await _tocar(tester, find.byKey(const Key('hora-10:00')));
      await _tocar(tester, find.byKey(const Key('agendar-cita')));
      await _tocar(tester, find.widgetWithText(FilledButton, 'Agendar'));

      expect(repo.altas.single['acepta_whatsapp'], true);
      expect(repo.agendadas.single['persona_id'], 'nuevo-1');
    });

    testWidgets(
      'si el servidor rechaza la hora lo dice, no se cierra y al reintentar no '
      'duplica al cliente',
      (tester) async {
        final repo = _Repo()
          ..rechazarAgenda = _rechazo('Ese horario ya no está disponible.');
        await _abrirAgenda(tester, repo);
        await _tocar(tester, find.byKey(const Key('nueva-cita')));
        await _buscarCliente(tester, 'Pedro Páramo');
        await _tocar(tester, find.byKey(const Key('cliente-nuevo')));
        await _tocar(tester, find.byKey(const Key('hora-11:00')));
        await _tocar(tester, find.byKey(const Key('agendar-cita')));
        await _tocar(tester, find.widgetWithText(FilledButton, 'Agendar'));

        expect(find.text('Ese horario ya no está disponible.'), findsOneWidget);
        expect(find.byKey(const Key('agendar-cita')), findsOneWidget);
        expect(repo.agendadas, isEmpty);
        expect(repo.altas, hasLength(1));
        // Ya quedó dado de alta: ahora es el cliente elegido.
        expect(find.byKey(const Key('cliente-elegido')), findsOneWidget);

        repo.rechazarAgenda = null;
        await _tocar(tester, find.byKey(const Key('hora-11:00')));
        await _tocar(tester, find.byKey(const Key('agendar-cita')));
        await _tocar(tester, find.widgetWithText(FilledButton, 'Agendar'));

        expect(repo.altas, hasLength(1));
        expect(repo.agendadas.single['persona_id'], 'nuevo-1');
        expect(find.byKey(const Key('agendar-cita')), findsNothing);
      },
    );
  });

  group('reprogramar desde la hoja de la cita', () {
    testWidgets(
      'a otra hora y con otra persona: confirma antes → ahora y manda lo de la web',
      (tester) async {
        final repo = _Repo([
          _citaJson(
            id: 'c1',
            instructorId: 'p2',
            instructor: 'Beto Ramírez',
            cliente: 'Ana López',
            hora: 9,
          ),
        ]);
        await _abrirAgenda(tester, repo);
        await _tocar(tester, find.text('Ana López'));
        await _tocar(tester, find.byKey(const Key('reprogramar')));

        // Parte de su profesional, su sucursal y su día; su propio horario no cuenta
        // como ocupado (moverla unos minutos).
        expect(repo.horas.last, {
          'instructor_id': 'p2',
          'sucursal_id': 's1',
          'fecha': _ymd(_hoy),
          'oferta_id': 'o1',
          'excluir_reserva': 'r-c1',
        });
        final antes = Formato.diaHora(
          DateTime(_hoy.year, _hoy.month, _hoy.day, 9),
        );
        expect(find.text('Ahora: $antes con Beto Ramírez'), findsOneWidget);

        await _tocar(tester, find.byKey(const Key('profesional-reprogramar')));
        await _tocar(tester, find.text('Alma Ruiz').last);
        expect(repo.horas.last['instructor_id'], 'p1');

        await _tocar(tester, find.byKey(const Key('hora-10:30')));
        await _tocar(tester, find.byKey(const Key('reprogramar-cita')));

        final ahora = Formato.diaHora(
          DateTime.utc(_hoy.year, _hoy.month, _hoy.day, 10, 30),
        );
        expect(find.text('¿Mover la cita de Ana López?'), findsOneWidget);
        expect(_fila('$antes con Beto Ramírez'), findsOneWidget);
        expect(_fila('$ahora con Alma Ruiz'), findsOneWidget);
        await _tocar(tester, find.widgetWithText(FilledButton, 'Mover'));

        // POST /reservas/{id}/reprogramar con la hora de la sucursal y la persona.
        expect(repo.movidas, [
          {
            'reserva': 'r-c1',
            'inicia_en_local': '${_ymd(_hoy)} 10:30:00',
            'instructor_id': 'p1',
          },
        ]);
        expect(
          find.text('Movida: antes $antes, ahora $ahora.'),
          findsOneWidget,
        );
        // Se cierran la hoja de reprogramar y la de la cita.
        expect(find.byKey(const Key('reprogramar-cita')), findsNothing);
        expect(find.byKey(const Key('reprogramar')), findsNothing);
      },
    );
  });

  group('sin permiso no se ofrece', () {
    final cita = _citaJson(
      id: 'c1',
      instructorId: 'p2',
      instructor: 'Beto Ramírez',
      cliente: 'Ana López',
      hora: 9,
    );

    testWidgets('quien imparte sin gestionar reservas no agenda ni mueve', (
      tester,
    ) async {
      await _abrirAgenda(
        tester,
        _Repo([cita]),
        sesion: _sesion(
          rol: 'instructor',
          permisos: const ['agenda.ver', 'reservas.ver', 'asistencia.marcar'],
        ),
      );
      expect(find.byKey(const Key('nueva-cita')), findsNothing);

      await _tocar(tester, find.text('Ana López'));
      expect(find.text('Cancelar cita'), findsNothing);
      expect(find.byKey(const Key('reprogramar')), findsNothing);
    });

    testWidgets('un negocio de clases no tiene «Nueva cita»', (tester) async {
      await _abrirAgenda(
        tester,
        _Repo(),
        sesion: _sesion(modalidad: Modalidad.clases),
      );
      expect(find.byKey(const Key('nueva-cita')), findsNothing);
    });

    testWidgets('sin gestionar clientes se busca, pero no se da de alta', (
      tester,
    ) async {
      await _abrirAgenda(
        tester,
        _Repo(),
        sesion: _sesion(
          permisos: _permisosRecepcion
              .where((p) => p != 'miembros.gestionar')
              .toList(),
        ),
      );
      await _tocar(tester, find.byKey(const Key('nueva-cita')));
      expect(find.byKey(const Key('buscar-cliente')), findsOneWidget);
      expect(find.byKey(const Key('cliente-nuevo')), findsNothing);
    });
  });

  group('los pedidos al API son los de la web', () {
    late _Api api;
    late AgendaRepository repo;

    setUp(() {
      api = _Api({
        'POST /agenda/citas': _citaJson(
          id: 'n1',
          instructorId: 'p1',
          instructor: 'Alma Ruiz',
          cliente: 'Ana López',
          hora: 10,
        ),
        'POST /reservas/r1/reprogramar': {
          'tipo': 'cita',
          'reserva': 'r1',
          'estado': 'confirmada',
          'sesion_id': 'c1',
          'antes': '2026-10-02T15:00:00+00:00',
          'ahora': '2026-10-02T18:00:00+00:00',
        },
        'POST /miembros': {
          'id': 'n2',
          'nombre': 'Pedro',
          'nombre_completo': 'Pedro Páramo',
          'celular': '55 1111 2222',
        },
        'GET /miembros': [
          {
            'id': 'a1',
            'nombre': 'Ana',
            'nombre_completo': 'Ana López',
            'email': null,
            'celular': '55 1234 5678',
          },
        ],
        'GET /disponibilidad': {
          'fecha': '2026-10-02',
          'slots': [
            {
              'inicia': '2026-10-02T16:00:00+00:00',
              'termina': '2026-10-02T16:30:00+00:00',
              'inicia_local': '2026-10-02T10:00',
            },
          ],
        },
        'GET /ofertas': [
          {
            'id': 'o1',
            'nombre': 'Corte',
            'precio_clase_minor': 15000,
            'politica_reserva': 'pago',
            'duracion_minutos': 30,
          },
          {
            'id': 'o2',
            'nombre': 'Barba',
            'precio_clase_minor': null,
            'politica_reserva': 'entitlement',
            'duracion_minutos': null,
          },
        ],
      });
      final dio = Dio(BaseOptions(baseUrl: 'https://demo.test'))
        ..httpClientAdapter = api;
      repo = AgendaRepository(dio, 'demo');
    });

    test('agendar: POST /agenda/citas con la hora de la sucursal', () async {
      final creada = await repo.agendarCita(
        NuevaCita(
          personaId: 'a1',
          ofertaId: 'o1',
          sucursalId: 's1',
          instructorId: 'p1',
          inicia: DateTime.utc(2026, 10, 2, 10, 30),
          duracionMinutos: 30,
        ),
      );

      final p = api.pedidos.single;
      expect(p.method, 'POST');
      expect(p.path, '/api/v1/app/demo/agenda/citas');
      // Lo mismo que la web, con la clave del intento (ADR 0201): un reintento de lo
      // mismo no agenda otra cita.
      final datos = Map<String, dynamic>.from(p.data as Map);
      expect(
        datos.remove('idempotency_key'),
        matches(RegExp(r'^[0-9a-f]{32}$')),
      );
      expect(datos, {
        'persona_id': 'a1',
        'oferta_id': 'o1',
        'sucursal_id': 's1',
        'instructor_id': 'p1',
        'inicia_en_local': '2026-10-02 10:30:00',
        'duracion_minutos': 30,
      });
      expect(creada.id, 'n1');
      expect(creada.cita?.cliente, 'Ana López');
    });

    test(
      'reprogramar: POST /reservas/{id}/reprogramar (persona opcional)',
      () async {
        final movida = await repo.reprogramarCita(
          'r1',
          inicia: DateTime.utc(2026, 10, 2, 12),
          instructorId: 'p2',
        );
        await repo.reprogramarCita('r1', inicia: DateTime.utc(2026, 10, 2, 12));

        expect(api.pedidos.first.method, 'POST');
        expect(
          api.pedidos.first.path,
          '/api/v1/app/demo/reservas/r1/reprogramar',
        );
        expect(api.pedidos.first.data, {
          'inicia_en_local': '2026-10-02 12:00:00',
          'instructor_id': 'p2',
        });
        // Sin otra persona, solo la hora (como la web).
        expect(api.pedidos.last.data, {
          'inicia_en_local': '2026-10-02 12:00:00',
        });
        expect(movida.antes, DateTime.utc(2026, 10, 2, 15));
        expect(movida.ahora, DateTime.utc(2026, 10, 2, 18));
      },
    );

    test('buscar y dar de alta clientes: GET y POST /miembros', () async {
      final encontrados = await repo.buscarClientes('ana');
      await repo.crearCliente(
        nombre: 'Pedro Páramo',
        celular: '55 1111 2222',
        sucursalId: 's1',
        aceptaWhatsApp: true,
      );
      await repo.crearCliente(
        nombre: 'Susana San Juan',
        email: 'susana@x.mx',
        sucursalId: 's1',
      );

      final buscar = api.pedidos[0];
      expect(buscar.method, 'GET');
      expect(buscar.path, '/api/v1/app/demo/miembros');
      expect(buscar.queryParameters, {
        'tipo': 'miembro',
        'q': 'ana',
        'page': 1,
        'per_page': 8,
      });
      expect(encontrados.single.nombre, 'Ana López');
      expect(encontrados.single.detalle, '55 1234 5678');

      expect(api.pedidos[1].method, 'POST');
      expect(api.pedidos[1].data, {
        'nombre': 'Pedro Páramo',
        'celular': '55 1111 2222',
        'acepta_whatsapp': true,
        'tipo': 'miembro',
        'sucursal_id': 's1',
      });
      expect(api.pedidos[2].data, {
        'nombre': 'Susana San Juan',
        'celular': null,
        'email': 'susana@x.mx',
        'tipo': 'miembro',
        'sucursal_id': 's1',
      });
    });

    test(
      'huecos libres: GET /disponibilidad del profesional ese día',
      () async {
        final horarios = await repo.horariosLibres(
          instructorId: 'p1',
          sucursalId: 's1',
          fecha: DateTime(2026, 10, 2),
          ofertaId: 'o1',
          duracionMinutos: 30,
        );

        final p = api.pedidos.single;
        expect(p.path, '/api/v1/app/demo/disponibilidad');
        expect(p.queryParameters, {
          'instructor_id': 'p1',
          'sucursal_id': 's1',
          'fecha': '2026-10-02',
          'oferta_id': 'o1',
          'duracion_minutos': 30,
        });
        // La hora es la de la sucursal (no la del teléfono).
        expect(horarios.single.hora, '10:00');
        expect(
          paredDeHorario(horarios.single.iniciaEnLocal),
          DateTime.utc(2026, 10, 2, 10),
        );
      },
    );

    test(
      'servicios: los de GET /ofertas, con su aviso como en la web',
      () async {
        final servicios = await repo.serviciosParaCita();

        expect(api.pedidos.single.path, '/api/v1/app/demo/ofertas');
        expect(servicios.first.etiqueta('MXN'), 'Corte · 30 min · \$150.00');
        expect(
          servicios.first.aviso('MXN'),
          'Queda confirmada y se cobra en caja: \$150.00.',
        );
        expect(servicios.last.duracionMinutos, isNull);
        expect(
          servicios.last.aviso('MXN'),
          'Queda confirmada y usa un crédito de su membresía.',
        );
      },
    );
  });
}

/// Responde como el API según el método y la ruta (lo demás, 404) y anota cada
/// pedido.
class _Api implements HttpClientAdapter {
  _Api(this.rutas);

  final Map<String, Object> rutas;
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    // «GET /miembros» o «POST /miembros»: el mismo camino responde según el método.
    final ruta = rutas.keys.where((r) {
      final [metodo, camino] = r.split(' ');
      return metodo == options.method && options.path.endsWith(camino);
    }).firstOrNull;
    return ResponseBody.fromString(
      jsonEncode(ruta == null ? {'message': 'No'} : {'data': rutas[ruta]}),
      ruta == null ? 404 : (options.method == 'POST' ? 201 : 200),
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
