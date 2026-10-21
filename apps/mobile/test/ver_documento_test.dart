import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/archivos/archivos_nativos.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/mis_documentos_screen.dart';

/// Ver un documento suyo en la app (como en la web): el archivo es privado, así que
/// se baja con su sesión; una foto se ve dentro de la app y un PDF se abre con el
/// visor del teléfono. Datos sintéticos, sin red real.

/// Un PNG de 1×1 (transparente).
final _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
);

class _ApiDocumentos implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    if (options.path.endsWith('/mi/documentos/d-foto')) {
      return ResponseBody.fromBytes(
        _png,
        200,
        headers: {
          Headers.contentTypeHeader: ['image/png'],
        },
      );
    }
    if (options.path.endsWith('/mi/documentos/d-pdf')) {
      // Sin tipo (o genérico): se sabe por la extensión del nombre.
      return ResponseBody.fromBytes(
        utf8.encode('%PDF-1.4'),
        200,
        headers: {
          Headers.contentTypeHeader: ['application/octet-stream'],
        },
      );
    }
    return ResponseBody.fromString(
      jsonEncode({
        'data': {
          'requisitos': [
            {
              'tipo': {
                'id': 't1',
                'nombre': 'Identificación',
                'descripcion': null,
                'obligatorio': true,
              },
              'documento': {
                'id': 'd-foto',
                'nombre': 'ine.png',
                'estado': 'aprobado',
                'motivo': null,
                'subido_en': '2026-10-01T12:00:00Z',
              },
            },
            {
              'tipo': {'id': 't2', 'nombre': 'Certificado médico'},
              'documento': null,
            },
          ],
          'otros': [
            {
              'id': 'd-pdf',
              'nombre': 'receta.pdf',
              'estado': 'pendiente',
              'motivo': null,
              'subido_en': null,
            },
          ],
        },
      }),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<_ApiDocumentos> _montar(WidgetTester tester) async {
  final api = _ApiDocumentos();
  tester.view.physicalSize = const Size(390, 1200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cuentaRepositoryProvider.overrideWithValue(
          CuentaRepository(Dio()..httpClientAdapter = api, 'demo'),
        ),
      ],
      child: const MaterialApp(home: MisDocumentosScreen()),
    ),
  );
  await tester.pumpAndSettle();
  return api;
}

void main() {
  test('el tipo lo dice el servidor o, si no, la extensión', () {
    expect(
      ArchivoDescargado.tipoDe('image/jpeg; charset=binary', 'x.pdf'),
      'image/jpeg',
    );
    expect(
      ArchivoDescargado.tipoDe('application/octet-stream', 'receta.PDF'),
      'application/pdf',
    );
    expect(ArchivoDescargado.tipoDe(null, 'foto.jpg'), 'image/jpeg');
    expect(ArchivoDescargado.tipoDe(null, 'sin-extension'), isNull);
  });

  test('lee lo que subió (con su id) y lo baja con su sesión', () async {
    final api = _ApiDocumentos();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    final documentos = await repo.misDocumentos();
    final archivo = await repo.documento('d-foto', nombre: 'ine.png');

    expect(documentos.requisitos.first.documento?.id, 'd-foto');
    expect(documentos.requisitos.last.documento, isNull);
    expect(documentos.otros.single.nombre, 'receta.pdf');
    expect(api.pedidos.last.path, '/api/v1/app/demo/mi/documentos/d-foto');
    expect(api.pedidos.last.responseType, ResponseType.bytes);
    expect(archivo.esImagen, isTrue);
    expect(archivo.bytes, _png);
  });

  testWidgets('una foto se ve dentro de la app', (tester) async {
    await _montar(tester);

    // Lo aprobado ya no se vuelve a subir, pero se puede ver.
    expect(find.text('Subir otro'), findsNothing);
    expect(find.text('Subir'), findsOneWidget);
    await tester.tap(find.byKey(const Key('ver-documento-d-foto')));
    await tester.pumpAndSettle();

    expect(find.byType(VisorImagenDocumento), findsOneWidget);
    expect(find.text('ine.png'), findsOneWidget);
  });

  testWidgets('un PDF se abre con el visor del teléfono', (tester) async {
    final llamadas = <MethodCall>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      ArchivosNativos.canal,
      (llamada) async {
        llamadas.add(llamada);
        return true;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        ArchivosNativos.canal,
        null,
      ),
    );
    await _montar(tester);

    expect(find.text('Otros documentos'), findsOneWidget);
    await tester.tap(find.byKey(const Key('ver-documento-d-pdf')));
    await tester.pumpAndSettle();

    final abrir = llamadas.single;
    expect(abrir.method, 'abrir');
    final datos = abrir.arguments as Map;
    expect(datos['nombre'], 'receta.pdf');
    expect(datos['mime'], 'application/pdf');
    expect(datos['bytes'], utf8.encode('%PDF-1.4'));
    expect(find.byType(VisorImagenDocumento), findsNothing);
  });

  testWidgets('si el teléfono no tiene con qué abrirlo, lo dice', (
    tester,
  ) async {
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      ArchivosNativos.canal,
      (_) async => false,
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        ArchivosNativos.canal,
        null,
      ),
    );
    await _montar(tester);

    await tester.tap(find.byKey(const Key('ver-documento-d-pdf')));
    await tester.pumpAndSettle();

    expect(
      find.text('No encontramos una app para abrir este documento.'),
      findsOneWidget,
    );
  });
}
