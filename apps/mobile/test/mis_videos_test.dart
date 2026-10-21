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
import 'package:agendauno/features/cuenta/data/videos.dart';
import 'package:agendauno/features/cuenta/presentation/mis_videos_screen.dart';

/// La biblioteca de videos del negocio en la app (ADR 0184), como en la web: los de
/// sus planes se abren; de los demás, qué plan los incluye. Solo en clases. Datos
/// sintéticos, sin red real.

final _videos = [
  {
    'id': 'v1',
    'titulo': 'Calentamiento',
    'descripcion': 'Para antes de tu clase.',
    'url': 'https://youtu.be/abcdef123',
    'duracion_minutos': 12,
    'categoria': 'Rutinas',
    'publicado': true,
    'orden': 0,
    'planes': <Object>[],
    'disponible': true,
  },
  {
    'id': 'v2',
    'titulo': 'Giros avanzados',
    'descripcion': null,
    'url': null,
    'duracion_minutos': null,
    'categoria': null,
    'publicado': true,
    'orden': 1,
    'planes': [
      {'id': 'p1', 'nombre': 'Mensual ilimitado'},
    ],
    'disponible': false,
  },
  {
    'id': 'v3',
    'titulo': 'Estiramiento',
    'url': 'https://vimeo.com/123456',
    'categoria': 'Rutinas',
    'planes': <Object>[],
    'disponible': true,
  },
];

class _ApiVideos implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    return ResponseBody.fromString(
      jsonEncode({'data': _videos}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

class _CuentaQuieta extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);
}

Sesion _sesion(Modalidad modalidad) => Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Ana',
  rol: 'miembro',
  modalidad: modalidad,
  estudioNombre: 'Estudio Luna',
);

void main() {
  test('lee cada video: si lo incluye su plan y cuáles lo incluyen', () {
    final lista = _videos.map(VideoMiembro.desdeJson).toList();

    expect(lista[0].sePuedeVer, isTrue);
    expect(lista[0].duracionMinutos, 12);
    expect(lista[1].sePuedeVer, isFalse);
    expect(lista[1].comoTenerlo, 'Incluido en: Mensual ilimitado.');
    // Solo https: otro esquema no se abre aunque esté disponible.
    expect(
      VideoMiembro.desdeJson({
        'id': 'x',
        'titulo': 'X',
        'url': 'javascript:alert(1)',
        'disponible': true,
      }).sePuedeVer,
      isFalse,
    );
  });

  test('por categoría en el orden en que llegan; sin categoría, al final', () {
    final grupos = VideoMiembro.porCategoria(
      _videos.map(VideoMiembro.desdeJson).toList(),
    );

    expect(grupos.map((g) => g.$1), ['Rutinas', '']);
    expect(grupos.first.$2.map((v) => v.id), ['v1', 'v3']);
  });

  test('pide los videos de su cuenta', () async {
    final api = _ApiVideos();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    final videos = await repo.videos();

    expect(api.pedidos.single.method, 'GET');
    expect(api.pedidos.single.path, '/api/v1/app/demo/mi/videos');
    expect(videos, hasLength(3));
  });

  testWidgets('se abren los de su plan; de los demás, qué plan los incluye', (
    tester,
  ) async {
    final api = _ApiVideos();
    var aPlanes = 0;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(_sesion(Modalidad.clases)),
          cuentaRepositoryProvider.overrideWithValue(
            CuentaRepository(Dio()..httpClientAdapter = api, 'demo'),
          ),
          cuentaProvider.overrideWith(_CuentaQuieta.new),
        ],
        child: MaterialApp(
          home: Builder(
            builder: (context) => TextButton(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => MisVideosScreen(onVerPlanes: () => aPlanes++),
                ),
              ),
              child: const Text('Abrir'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Abrir'));
    await tester.pumpAndSettle();

    expect(find.text('Rutinas'), findsOneWidget);
    expect(
      find.text('Clases grabadas y rutinas de Estudio Luna.'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('ver-video-v1')), findsOneWidget);
    expect(find.byKey(const Key('ver-video-v3')), findsOneWidget);
    expect(find.byKey(const Key('ver-video-v2')), findsNothing);
    expect(find.text('Incluido en: Mensual ilimitado.'), findsOneWidget);

    await tester.tap(find.text('Ver planes'));
    await tester.pumpAndSettle();
    expect(aPlanes, 1);
    expect(find.byType(MisVideosScreen), findsNothing);
  });

  test('en un negocio de citas no se piden', () async {
    final api = _ApiVideos();
    final contenedor = ProviderContainer(
      overrides: [
        sesionInicialProvider.overrideWithValue(_sesion(Modalidad.citas)),
        cuentaRepositoryProvider.overrideWithValue(
          CuentaRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
        cuentaProvider.overrideWith(_CuentaQuieta.new),
      ],
    );
    addTearDown(contenedor.dispose);

    final escucha = contenedor.listen(videosProvider.future, (_, _) {});
    addTearDown(escucha.close);

    expect(await escucha.read(), isEmpty);
    expect(api.pedidos, isEmpty);
  });
}
