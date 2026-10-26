import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/bandeja/data/bandeja_repository.dart';
import 'package:agendauno/features/bandeja/presentation/bandeja_screen.dart';

/// La bandeja en la app, como la web: las conversaciones de página en página, los
/// mensajes anteriores por tandas (`hay_anteriores`) y, por WhatsApp fuera de las 24
/// horas, una plantilla aprobada con sus datos y su vista previa. Sin red real.

Map<String, dynamic> _mensaje(String id, String texto) => {
  'id': id,
  'direccion': 'entrante',
  'tipo': 'texto',
  'texto': texto,
  'enviado_en': '2030-01-08T16:00:00Z',
};

Map<String, dynamic> _conversacion(
  String id, {
  bool puedeContestar = true,
  bool hayAnteriores = false,
  List<Map<String, dynamic>> mensajes = const [],
}) => {
  'id': id,
  'canal': 'whatsapp',
  'contacto': 'Cliente $id',
  'no_leidos': 0,
  'ultimo_texto': 'Hola',
  'ultimo_mensaje_en': '2030-01-08T16:00:00Z',
  'puede_contestar': puedeContestar,
  'contestar_hasta': null,
  'como_agente': false,
  'mensajes': mensajes,
  'hay_anteriores': hayAnteriores,
};

class _ApiPaginas implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    final Object cuerpo = switch (options.path) {
      final p when p.endsWith('/conversaciones') => {
        'data': [_conversacion('c${options.queryParameters['page']}')],
        'meta': {'page': options.queryParameters['page'], 'ultima_pagina': 2},
      },
      final p when p.endsWith('/mensajes') => {
        'data': [_mensaje('m0', 'Lo primero')],
        'meta': {'hay_anteriores': false},
      },
      final p when p.endsWith('/plantillas') => {
        'data': [
          {
            'nombre': 'recordatorio',
            'idioma': 'es_MX',
            'categoria': 'utility',
            'encabezado': null,
            'cuerpo': 'Hola {{1}}, tu cita es el {{2}}.',
            'pie': 'Estudio Luna',
            'variables': 2,
          },
        ],
      },
      _ => {'data': <String, dynamic>{}},
    };
    return ResponseBody.fromString(
      jsonEncode(cuerpo),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

class _BandejaFalsa extends BandejaRepository {
  _BandejaFalsa({this.puedeContestar = true}) : super(Dio(), 'demo');

  final bool puedeContestar;
  final hechas = <String>[];

  @override
  Future<PaginaConversaciones> conversaciones({
    String? q,
    int pagina = 1,
  }) async {
    hechas.add('pagina:$pagina');
    return PaginaConversaciones(
      conversaciones: [
        // La de la página 1 sube y vuelve a salir en la 2: no se repite.
        ConversacionBandeja.desdeJson(_conversacion('c1')),
        if (pagina == 2) ConversacionBandeja.desdeJson(_conversacion('c2')),
      ],
      pagina: pagina,
      ultimaPagina: 2,
    );
  }

  @override
  Future<ConversacionBandeja> conversacion(String id) async =>
      ConversacionBandeja.desdeJson(
        _conversacion(
          id,
          puedeContestar: puedeContestar,
          hayAnteriores: true,
          mensajes: [_mensaje('m1', 'Más reciente')],
        ),
      );

  @override
  Future<({List<MensajeBandeja> mensajes, bool hayAnteriores})> anteriores(
    String id,
    String antes,
  ) async {
    hechas.add('anteriores:$antes');
    return (
      mensajes: [MensajeBandeja.desdeJson(_mensaje('m0', 'Lo primero'))],
      hayAnteriores: false,
    );
  }

  @override
  Future<List<PlantillaWhatsApp>> plantillas() async => [
    const PlantillaWhatsApp(
      nombre: 'recordatorio',
      idioma: 'es_MX',
      categoria: 'utility',
      cuerpo: 'Hola {{1}}, tu cita es el {{2}}.',
      variables: 2,
    ),
  ];

  @override
  Future<void> mandarPlantilla(
    String id,
    PlantillaWhatsApp p,
    List<String> valores,
  ) async => hechas.add('plantilla:$id:${p.nombre}:${valores.join('|')}');
}

Future<void> _montarBandeja(
  WidgetTester tester,
  _BandejaFalsa repo,
  Widget w,
) => tester.pumpWidget(
  ProviderScope(
    overrides: [
      sesionInicialProvider.overrideWithValue(
        const Sesion(
          slug: 'demo',
          bearer: 't',
          nombre: 'Recepción',
          rol: 'recepcionista',
          permisos: ['*'],
        ),
      ),
      bandejaRepositoryProvider.overrideWithValue(repo),
    ],
    child: MaterialApp(home: Scaffold(body: w)),
  ),
);

void main() {
  test('las rutas: página, mensajes anteriores y plantillas', () async {
    final api = _ApiPaginas();
    final repo = BandejaRepository(Dio()..httpClientAdapter = api, 'demo');

    final pagina = await repo.conversaciones(pagina: 2);
    final tanda = await repo.anteriores('c1', 'm1');
    final plantillas = await repo.plantillas();
    await repo.mandarPlantilla('c1', plantillas.single, [' Ana ', 'lunes']);

    expect(pagina.pagina, 2);
    expect(pagina.hayMas, isFalse);
    expect(tanda.mensajes.single.id, 'm0');
    expect(tanda.hayAnteriores, isFalse);
    final [lista, anteriores, leer, mandar] = api.pedidos;
    expect(lista.queryParameters, {'page': 2});
    expect(
      anteriores.path,
      '/api/v1/app/demo/bandeja/conversaciones/c1/mensajes',
    );
    expect(anteriores.queryParameters, {'antes': 'm1'});
    expect(leer.path, '/api/v1/app/demo/bandeja/plantillas');
    expect(mandar.path, '/api/v1/app/demo/bandeja/conversaciones/c1/plantilla');
    expect(mandar.data, {
      'nombre': 'recordatorio',
      'idioma': 'es_MX',
      'variables': ['Ana', 'lunes'],
    });
  });

  test('la vista previa y los datos que acepta Meta', () {
    const p = PlantillaWhatsApp(
      nombre: 'r',
      idioma: 'es',
      encabezado: 'Aviso',
      cuerpo: 'Hola {{1}}, tu cita es el {{2}}.',
      pie: 'Estudio',
      variables: 2,
    );
    expect(
      p.vistaPrevia(['Ana', '']),
      'Aviso\n\nHola Ana, tu cita es el {{2}}.\n\nEstudio',
    );
    expect(PlantillaWhatsApp.datoValido('Ana'), isTrue);
    expect(PlantillaWhatsApp.datoValido('  '), isFalse);
    expect(PlantillaWhatsApp.datoValido('dos\nlíneas'), isFalse);
    expect(PlantillaWhatsApp.datoValido('muchos     espacios'), isFalse);
  });

  test('al releer, los mensajes anteriores ya cargados se quedan', () {
    final vieja =
        ConversacionBandeja.desdeJson(
          _conversacion('c1', mensajes: [_mensaje('m1', 'a')]),
        ).conAnteriores([
          MensajeBandeja.desdeJson(_mensaje('m0', 'b')),
        ], hayMas: true);
    final nueva = ConversacionBandeja.desdeJson(
      _conversacion('c1', mensajes: [_mensaje('m1', 'a'), _mensaje('m2', 'c')]),
    ).conservando(vieja);

    expect(nueva.mensajes.map((m) => m.id), ['m0', 'm1', 'm2']);
    expect(nueva.hayAnteriores, isTrue);
  });

  testWidgets('«Ver más conversaciones» trae la siguiente página sin repetir', (
    tester,
  ) async {
    final repo = _BandejaFalsa();
    await _montarBandeja(tester, repo, const BandejaScreen());
    await tester.pumpAndSettle();

    expect(find.text('Cliente c1'), findsOneWidget);
    await tester.tap(find.byKey(const Key('mas-conversaciones')));
    await tester.pumpAndSettle();

    expect(repo.hechas, ['pagina:1', 'pagina:2']);
    expect(find.text('Cliente c1'), findsOneWidget);
    expect(find.text('Cliente c2'), findsOneWidget);
    expect(find.byKey(const Key('mas-conversaciones')), findsNothing);
  });

  testWidgets('carga los mensajes anteriores por tandas', (tester) async {
    final repo = _BandejaFalsa();
    await _montarBandeja(tester, repo, const ConversacionScreen(id: 'c1'));
    await tester.pumpAndSettle();

    expect(find.text('Más reciente'), findsOneWidget);
    await tester.tap(find.byKey(const Key('mensajes-anteriores')));
    await tester.pumpAndSettle();

    expect(repo.hechas, ['anteriores:m1']);
    expect(find.text('Lo primero'), findsOneWidget);
    expect(find.byKey(const Key('mensajes-anteriores')), findsNothing);
  });

  testWidgets('fuera del plazo, por WhatsApp se manda una plantilla', (
    tester,
  ) async {
    final repo = _BandejaFalsa(puedeContestar: false);
    await _montarBandeja(tester, repo, const ConversacionScreen(id: 'c1'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('fuera-de-plazo')), findsOneWidget);
    expect(find.textContaining('panel web'), findsNothing);
    await tester.tap(find.byKey(const Key('con-plantilla')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('elegir-plantilla')));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('recordatorio').last);
    await tester.pumpAndSettle();
    // Sin sus datos no se manda.
    expect(
      tester
          .widget<FilledButton>(find.byKey(const Key('mandar-plantilla')))
          .onPressed,
      isNull,
    );
    await tester.enterText(find.byKey(const Key('dato-plantilla-0')), 'Ana');
    await tester.enterText(find.byKey(const Key('dato-plantilla-1')), 'lunes');
    await tester.pump();
    expect(find.text('Hola Ana, tu cita es el lunes.'), findsOneWidget);
    await tester.tap(find.byKey(const Key('mandar-plantilla')));
    await tester.pumpAndSettle();

    expect(repo.hechas, ['plantilla:c1:recordatorio:Ana|lunes']);
    expect(find.text('Plantilla enviada.'), findsOneWidget);
  });
}
