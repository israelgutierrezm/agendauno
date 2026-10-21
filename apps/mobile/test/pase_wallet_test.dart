import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/pase_sheet.dart';

/// El pase de entrada se guarda también en Google Wallet (ADR 0195) solo en Android y
/// solo si el negocio lo permite; el QR que se renueva sigue ahí.

class _Repo extends CuentaRepository {
  _Repo({required this.google}) : super(Dio(), 'demo');

  final bool google;

  @override
  Future<String> pase() async => 'AU1.codigo';

  @override
  Future<({bool apple, bool google})> walletDisponible() async =>
      (apple: false, google: google);
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
}
