import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/agenda/presentation/pase_lista_screen.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// El pase de lista completo en la app, como en la web: los invitados de los
/// clientes (se marcan), quien reservó desde Wellhub, TotalPass o Fitpass (llegó o
/// cancelar), lo apartado para recoger, agregar a quien llegó sin reservar, y el
/// cupo que cuadra con la lista (todos ocupan lugar). Cada acción con su permiso.
/// Datos sintéticos, sin red real.

Map<String, dynamic> _rosterJson({int capacidad = 8}) => {
  'data': [
    {'id': 'r1', 'persona': 'Ana López', 'estado': 'confirmada'},
    {
      'id': 'r2',
      'persona': 'Beto Ruiz',
      'estado': 'confirmada',
      'asistencia': 'presente',
    },
    {'id': 'r3', 'persona': 'Caro Díaz', 'estado': 'ofrecida'},
    {'id': 'r4', 'persona': 'Dani Paz', 'estado': 'en_espera'},
  ],
  'meta': {
    'empezo': false,
    'sesion': {'id': 's1', 'capacidad': capacidad, 'estado': 'programada'},
    'invitados': [
      {
        'id': 'i1',
        'nombre': 'Eva Sol',
        'anfitrion': 'Ana López',
        'estado': 'reservado',
      },
      // No vino: ya no ocupa lugar.
      {
        'id': 'i2',
        'nombre': 'Fer Gil',
        'anfitrion': null,
        'estado': 'no_asistio',
      },
    ],
    'plataforma': [
      {
        'id': 'p1',
        'proveedor': 'fitpass',
        'nombre': 'Gabi Mar',
        'estado': 'reservado',
        'puede_marcar': true,
      },
      {
        'id': 'p2',
        'proveedor': 'totalpass',
        'nombre': null,
        'estado': 'reservado',
        'puede_marcar': false,
      },
    ],
  },
};

class _RepoLista extends AgendaRepository {
  _RepoLista({this.capacidad = 8}) : super(Dio(), 'demo');

  final int capacidad;
  final hechas = <String>[];

  @override
  Future<ListaClase> roster(String sesionId) async =>
      ListaClase.desdeJson(_rosterJson(capacidad: capacidad));

  @override
  Future<List<ApartadoClase>> apartados(String sesionId) async =>
      ApartadoClase.lista([
        {
          'id': 'a1',
          'articulo': {'id': 'x', 'nombre': 'Agua'},
          'cantidad': 2,
          'total_minor': 4000,
          'moneda': 'MXN',
          'persona': {'id': 'per1', 'nombre': 'Ana López'},
        },
        {
          'id': 'a2',
          'articulo': {'id': 'y', 'nombre': 'Toalla'},
          'cantidad': 1,
          'total_minor': 5000,
          'moneda': 'MXN',
          'persona': {'id': 'per1', 'nombre': 'Ana López'},
        },
      ]);

  @override
  Future<void> marcarInvitado(
    String invitadoId, {
    required bool asistio,
  }) async => hechas.add('invitado:$invitadoId:$asistio');

  @override
  Future<void> llegoDePlataforma(
    String lugarId, {
    bool identificacionRevisada = false,
  }) async => hechas.add('llego:$lugarId:$identificacionRevisada');

  @override
  Future<void> cancelarDePlataforma(String lugarId) async =>
      hechas.add('cancelar:$lugarId');

  @override
  Future<List<ClienteAgenda>> buscarClientes(String texto) async => [
    const ClienteAgenda(id: 'per9', nombre: 'Hugo Luna', detalle: 'hugo@x.mx'),
  ];

  @override
  Future<void> agregarAClase(
    String sesionId,
    String personaId, {
    bool esperar = false,
  }) async => hechas.add('agregar:$sesionId:$personaId:$esperar');
}

final _claseLista = SesionAgenda.desdeJson({
  'id': 's1',
  'tipo': 'clase',
  'oferta': 'Pole Nivel 1',
  'inicia_en': DateTime.now()
      .add(const Duration(minutes: 30))
      .toUtc()
      .toIso8601String(),
  'termina_en': DateTime.now()
      .add(const Duration(minutes: 90))
      .toUtc()
      .toIso8601String(),
  'capacidad': 8,
  'ocupados': 6,
  'estado': 'programada',
});

Future<void> _abrirLista(
  WidgetTester tester,
  _RepoLista repo, {
  List<String> permisos = const ['*'],
}) async {
  tester.view.physicalSize = const Size(420, 1800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(
          Sesion(
            slug: 'demo',
            bearer: 't',
            nombre: 'Recepción',
            rol: 'recepcionista',
            permisos: permisos,
            modalidad: Modalidad.clases,
          ),
        ),
        agendaRepositoryProvider.overrideWithValue(repo),
      ],
      child: MaterialApp(home: PaseListaScreen(sesion: _claseLista)),
    ),
  );
  await tester.pumpAndSettle();
}

class _ApiRegistro implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  /// La primera petición se pierde (sin red) si se pide.
  bool perderLaPrimera = false;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    if (perderLaPrimera && pedidos.length == 1) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'sin red',
      );
    }
    return ResponseBody.fromString(
      jsonEncode({'data': <Object>[]}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test('el cupo de la lista cuenta reservas, invitados y plataformas', () {
    final lista = ListaClase.desdeJson(_rosterJson());

    expect(lista.invitados.map((i) => i.id), ['i1', 'i2']);
    expect(lista.plataforma.map((l) => l.proveedorNombre), [
      'Fitpass',
      'TotalPass',
    ]);
    // 3 reservas en sala + 1 invitado reservado + 2 de plataformas.
    expect(lista.ocupan, 6);
    expect(lista.llegaron, 1);
    expect(lista.libres(), 2);
    expect(ListaClase.desdeJson(_rosterJson(capacidad: 6)).libres(), 0);
    // Un API anterior sin `meta.sesion`: el cupo de la agenda.
    final anterior = ListaClase.desdeJson({
      'data': [
        {'id': 'r1', 'persona': 'Ana', 'estado': 'confirmada'},
      ],
    });
    expect(anterior.libres(4), 3);
    expect(anterior.libres(), isNull);
  });

  testWidgets('muestra invitados, plataformas y apartados; el cupo cuadra', (
    tester,
  ) async {
    await _abrirLista(tester, _RepoLista());

    expect(find.byKey(const Key('llegaron-de')), findsOneWidget);
    expect(find.text('1/6'), findsOneWidget);
    expect(find.text('2 lugares libres'), findsOneWidget);
    expect(find.text('Invitados · 2'), findsOneWidget);
    expect(find.text('invitado de Ana López'), findsOneWidget);
    expect(find.text('Reservas de plataformas · 2'), findsOneWidget);
    expect(find.text('Visitante'), findsOneWidget);
    // TotalPass valida la llegada en su propia app.
    expect(find.text('Hace check-in en su app'), findsOneWidget);
    expect(find.text('Apartados para recoger · 1'), findsOneWidget);
    expect(find.text('2 × Agua · 1 × Toalla'), findsOneWidget);
    expect(
      find.text('Se pagan en el mostrador al entregarlos.'),
      findsOneWidget,
    );
  });

  testWidgets('se marca a un invitado y llega quien viene de Fitpass', (
    tester,
  ) async {
    final repo = _RepoLista();
    await _abrirLista(tester, repo);

    final invitado = find.byKey(const ValueKey('invitado-i1'));
    await tester.tap(
      find.descendant(of: invitado, matching: find.text('Llegó')),
    );
    await tester.pumpAndSettle();

    // Fitpass pide revisar la identificación antes.
    final fitpass = find.byKey(const ValueKey('plataforma-p1'));
    await tester.tap(
      find.descendant(of: fitpass, matching: find.text('Llegó')),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('identificación oficial'), findsOneWidget);
    await tester.tap(find.text('Sí, la revisé'));
    await tester.pumpAndSettle();

    await tester.tap(
      find.descendant(of: fitpass, matching: find.text('Cancelar reserva')),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('La plataforma le avisará'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Cancelar reserva'));
    await tester.pumpAndSettle();

    expect(repo.hechas, ['invitado:i1:true', 'llego:p1:true', 'cancelar:p1']);
  });

  testWidgets('sin los permisos, ni agregar, ni plataformas, ni cancelar', (
    tester,
  ) async {
    // Quien imparte: pasa lista, pero no gestiona reservas ni registra check-ins.
    await _abrirLista(tester, _RepoLista(), permisos: ['asistencia.marcar']);

    expect(find.byKey(const Key('agregar-a-clase')), findsNothing);
    expect(find.text('Cancelar reserva'), findsNothing);
    final fitpass = find.byKey(const ValueKey('plataforma-p1'));
    expect(
      find.descendant(of: fitpass, matching: find.text('Llegó')),
      findsNothing,
    );
    // Los invitados sí se marcan (pasar lista).
    final invitado = find.byKey(const ValueKey('invitado-i1'));
    expect(
      find.descendant(of: invitado, matching: find.text('Llegó')),
      findsOneWidget,
    );
  });

  testWidgets('agrega a la clase a quien llegó sin reservar', (tester) async {
    final repo = _RepoLista();
    await _abrirLista(tester, repo);

    await tester.tap(find.byKey(const Key('agregar-a-clase')));
    await tester.pumpAndSettle();
    expect(find.text('Agregar a la clase'), findsWidgets);

    await tester.enterText(find.byKey(const Key('buscar-persona')), 'hu');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Hugo Luna'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('persona-elegida')), findsOneWidget);

    await tester.tap(find.byKey(const Key('confirmar-agregar')));
    await tester.pumpAndSettle();

    expect(repo.hechas, ['agregar:s1:per9:false']);
    expect(find.text('Hugo Luna se agregó a la clase.'), findsOneWidget);
  });

  testWidgets('llena, a quien llegó lo agrega a la lista de espera', (
    tester,
  ) async {
    final repo = _RepoLista(capacidad: 6);
    await _abrirLista(tester, repo);
    expect(find.text('Llena'), findsOneWidget);

    await tester.tap(find.byKey(const Key('agregar-a-clase')));
    await tester.pumpAndSettle();
    expect(
      find.text('La clase está llena: entrará a la lista de espera.'),
      findsOneWidget,
    );
    await tester.enterText(find.byKey(const Key('buscar-persona')), 'hu');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Hugo Luna'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Agregar a la lista de espera'));
    await tester.pumpAndSettle();

    expect(repo.hechas, ['agregar:s1:per9:true']);
    expect(find.text('Hugo Luna quedó en la lista de espera.'), findsOneWidget);
  });

  test(
    'las rutas del API, y agregar dos veces lo mismo es un intento',
    () async {
      final api = _ApiRegistro()..perderLaPrimera = true;
      final repo = AgendaRepository(Dio()..httpClientAdapter = api, 'demo');

      // Sin red la primera vez: el reintento lleva la misma clave.
      await expectLater(
        repo.agregarAClase('s1', 'per9'),
        throwsA(isA<DioException>()),
      );
      await repo.agregarAClase('s1', 'per9');
      await repo.marcarInvitado('i1', asistio: false);
      await repo.llegoDePlataforma('p1', identificacionRevisada: true);
      await repo.cancelarDePlataforma('p2');
      await repo.apartados('s1');

      final [primera, segunda, invitado, llego, cancelar, apartados] =
          api.pedidos;
      expect(primera.path, '/api/v1/app/demo/sesiones/s1/reservas');
      expect(primera.method, 'POST');
      expect(primera.data['persona_id'], 'per9');
      expect(primera.data['esperar'], isFalse);
      expect(segunda.data['idempotency_key'], primera.data['idempotency_key']);
      expect(invitado.method, 'PUT');
      expect(invitado.path, '/api/v1/app/demo/invitados/i1/asistencia');
      expect(invitado.data, {'asistio': false});
      expect(llego.path, '/api/v1/app/demo/lugares-plataforma/p1/llego');
      expect(llego.data, {'identificacion_revisada': true});
      expect(cancelar.path, '/api/v1/app/demo/lugares-plataforma/p2/cancelar');
      expect(apartados.method, 'GET');
      expect(apartados.path, '/api/v1/app/demo/sesiones/s1/apartados');
    },
  );
}
