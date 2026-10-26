import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/archivos/archivos_nativos.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/mis_documentos_screen.dart';
import 'package:agendauno/features/cuenta/presentation/pase_sheet.dart';

/// El cliente sube un PDF además de fotos en su Expediente (con el selector del
/// sistema, hasta 8 MB como el servidor) y renueva el pase de su cartera si perdió
/// el teléfono, como en la web. Sin red real.

class _ApiExpediente implements HttpClientAdapter {
  final subidas = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    if (options.method == 'POST') {
      subidas.add(options);
    }
    return ResponseBody.fromString(
      jsonEncode({
        'data': {
          'requisitos': [
            {
              'tipo': {'id': 't2', 'nombre': 'Certificado médico'},
              'documento': null,
            },
          ],
          'otros': <Object>[],
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

class _RepoPase extends CuentaRepository {
  _RepoPase() : super(Dio(), 'demo');

  var renovados = 0;

  @override
  Future<String> pase() async => 'AU1.codigo';

  @override
  Future<({bool apple, bool google})> walletDisponible() async =>
      (apple: false, google: true);

  @override
  Future<void> renovarPaseWallet() async => renovados++;
}

Future<_ApiExpediente> _montarExpediente(
  WidgetTester tester,
  Future<PdfElegido?> Function() elegirPdf,
) async {
  final api = _ApiExpediente();
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
      child: MaterialApp(home: MisDocumentosScreen(elegirPdf: elegirPdf)),
    ),
  );
  await tester.pumpAndSettle();
  return api;
}

void main() {
  test('el selector del teléfono: el PDF, uno muy pesado o nada', () async {
    TestWidgetsFlutterBinding.ensureInitialized();
    final mensajero =
        TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger;
    addTearDown(
      () => mensajero.setMockMethodCallHandler(ArchivosNativos.canal, null),
    );
    final respuestas = <Object?>[
      {'bytes': Uint8List.fromList(utf8.encode('%PDF-1.4')), 'nombre': 'acta'},
      {'demasiado': true},
      null,
    ];
    mensajero.setMockMethodCallHandler(ArchivosNativos.canal, (llamada) async {
      expect(llamada.method, 'elegirPdf');
      return respuestas.removeAt(0);
    });

    final pdf = await ArchivosNativos.elegirPdf();
    expect(pdf?.nombreConExtension, 'acta.pdf');
    expect(pdf?.bytes, utf8.encode('%PDF-1.4'));
    expect((await ArchivosNativos.elegirPdf())?.demasiado, isTrue);
    expect(await ArchivosNativos.elegirPdf(), isNull);
  });

  testWidgets('sube un PDF a lo que pide el negocio', (tester) async {
    final api = await _montarExpediente(
      tester,
      () async => PdfElegido(
        bytes: Uint8List.fromList(utf8.encode('%PDF-1.4')),
        nombre: 'certificado.pdf',
      ),
    );

    await tester.tap(find.text('Subir'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('subir-pdf')));
    await tester.pumpAndSettle();

    final datos = api.subidas.single.data as FormData;
    expect(api.subidas.single.path, '/api/v1/app/demo/mi/documentos');
    expect(datos.files.single.value.filename, 'certificado.pdf');
    expect(
      datos.fields.map((c) => '${c.key}=${c.value}'),
      contains('tipo_documento_id=t2'),
    );
    expect(find.text('Documento enviado. Queda en revisión.'), findsOneWidget);
  });

  testWidgets('un PDF de más de 8 MB no se sube', (tester) async {
    final api = await _montarExpediente(
      tester,
      () async => const PdfElegido.demasiado(),
    );

    await tester.tap(find.text('Subir'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('subir-pdf')));
    await tester.pumpAndSettle();

    expect(api.subidas, isEmpty);
    expect(find.textContaining('pesa más de 8 MB'), findsOneWidget);
  });

  testWidgets('renueva el pase de la cartera, confirmado', (tester) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    final repo = _RepoPase();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [cuentaRepositoryProvider.overrideWithValue(repo)],
        child: const MaterialApp(home: Scaffold(body: PaseSheet())),
      ),
    );
    await tester.pump();

    await tester.tap(find.byKey(const Key('pase-renovar-wallet')));
    await tester.pump();
    expect(find.textContaining('dejará de servir'), findsOneWidget);
    await tester.tap(find.text('Renovarlo'));
    await tester.pump();
    await tester.pump();

    expect(repo.renovados, 1);
    expect(find.textContaining('el pase anterior ya no sirve'), findsOneWidget);
    // Se desmonta para que pare el temporizador del QR.
    await tester.pumpWidget(const SizedBox());
    debugDefaultTargetPlatformOverride = null;
  });

  test('renovar va a su ruta', () async {
    final api = _ApiExpediente();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    await repo.renovarPaseWallet();

    expect(api.subidas.single.path, '/api/v1/app/demo/mi/pase/wallet/renovar');
  });
}
