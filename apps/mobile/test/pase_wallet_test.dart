import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/archivos/archivos_nativos.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/pase_sheet.dart';

/// El pase de entrada se guarda también en la cartera del teléfono (ADR 0195), solo
/// si el negocio lo permite: Google Wallet en Android y Apple Wallet en iPhone (el
/// `.pkpass` se baja con la sesión y el iPhone lo agrega). El QR que se renueva
/// sigue ahí.

class _Repo extends CuentaRepository {
  _Repo({required this.google, this.apple = false}) : super(Dio(), 'demo');

  final bool google;
  final bool apple;
  var pasesApple = 0;

  @override
  Future<String> pase() async => 'AU1.codigo';

  @override
  Future<({bool apple, bool google})> walletDisponible() async =>
      (apple: apple, google: google);

  @override
  Future<Uint8List> paseAppleWallet() async {
    pasesApple++;
    return Uint8List.fromList([80, 75, 3, 4]);
  }
}

/// Pide el `.pkpass` a la ruta del pase y lo devuelve en bytes (con la sesión).
class _ApiPase implements HttpClientAdapter {
  final pedidos = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidos.add(options);
    return ResponseBody.fromBytes(
      [80, 75, 3, 4],
      200,
      headers: {
        Headers.contentTypeHeader: ['application/vnd.apple.pkpass'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

Future<void> _montar(WidgetTester tester, _Repo repo) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [cuentaRepositoryProvider.overrideWithValue(repo)],
      child: const MaterialApp(home: Scaffold(body: PaseSheet())),
    ),
  );
  await tester.pump();
}

void main() {
  testWidgets('en Android con la cartera permitida ofrece Google Wallet', (
    tester,
  ) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    await _montar(tester, _Repo(google: true));
    expect(find.byKey(const Key('pase-google-wallet')), findsOneWidget);
    // Se desmonta para que pare el temporizador del QR.
    await tester.pumpWidget(const SizedBox());
    debugDefaultTargetPlatformOverride = null;
  });

  testWidgets('sin la cartera permitida solo queda el QR', (tester) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    await _montar(tester, _Repo(google: false));
    expect(find.byKey(const Key('pase-google-wallet')), findsNothing);
    await tester.pumpWidget(const SizedBox());
    debugDefaultTargetPlatformOverride = null;
  });

  testWidgets('en iPhone no se ofrece Google Wallet', (tester) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    await _montar(tester, _Repo(google: true));
    expect(find.byKey(const Key('pase-google-wallet')), findsNothing);
    await tester.pumpWidget(const SizedBox());
    debugDefaultTargetPlatformOverride = null;
  });

  testWidgets('en iPhone agrega el pase a Apple Wallet', (tester) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    final llamadas = <MethodCall>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      ArchivosNativos.canal,
      (llamada) async {
        llamadas.add(llamada);
        return true;
      },
    );
    final repo = _Repo(google: true, apple: true);
    await _montar(tester, repo);
    expect(find.byKey(const Key('pase-google-wallet')), findsNothing);

    await tester.tap(find.byKey(const Key('pase-apple-wallet')));
    await tester.pump();

    expect(repo.pasesApple, 1);
    expect(llamadas.single.method, 'agregarPaseWallet');
    expect((llamadas.single.arguments as Map)['bytes'], [80, 75, 3, 4]);
    await tester.pumpWidget(const SizedBox());
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      ArchivosNativos.canal,
      null,
    );
    debugDefaultTargetPlatformOverride = null;
  });

  testWidgets('en Android no se ofrece Apple Wallet', (tester) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    await _montar(tester, _Repo(google: false, apple: true));
    expect(find.byKey(const Key('pase-apple-wallet')), findsNothing);
    await tester.pumpWidget(const SizedBox());
    debugDefaultTargetPlatformOverride = null;
  });

  test('el pase de Apple se baja de su ruta, en bytes', () async {
    final api = _ApiPase();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    final pase = await repo.paseAppleWallet();

    expect(api.pedidos.single.path, '/api/v1/app/demo/mi/pase/wallet/apple');
    expect(api.pedidos.single.responseType, ResponseType.bytes);
    expect(pase, [80, 75, 3, 4]);
  });
}
