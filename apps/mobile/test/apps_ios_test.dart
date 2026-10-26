import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';

/// iPhone en la V1: el manifiesto de privacidad declara lo que la app recolecta
/// (como App Store Connect y la «Seguridad de los datos» de Play), el acceso explica
/// cómo entra quien solo usaba Google, y el widget de inicio es un target de verdad
/// del proyecto de Xcode (con su App Group por sabor). Se leen los archivos del
/// proyecto: aquí no hay Xcode.

String _leer(String ruta) => File(ruta).readAsStringSync();

void main() {
  test('el manifiesto de privacidad declara lo que la app recolecta', () {
    final privacidad = _leer('ios/Runner/PrivacyInfo.xcprivacy');
    for (final tipo in [
      'Name',
      'EmailAddress',
      'PhoneNumber',
      'PhotosorVideos',
      'OtherDataTypes',
      'DeviceID',
      'UserID',
      'PurchaseHistory',
      'PhysicalAddress',
      'OtherFinancialInfo',
      'Health',
      'OtherUserContent',
      'EmailsOrTextMessages',
      'CrashData',
    ]) {
      expect(
        privacidad,
        contains('<string>NSPrivacyCollectedDataType$tipo</string>'),
        reason: tipo,
      );
    }
    // No rastrea y todo es para que la app funcione.
    expect(privacidad, contains('<key>NSPrivacyTracking</key>\n\t<false/>'));
    expect(privacidad, isNot(contains('PurposeThirdPartyAdvertising')));
    expect(
      RegExp(
        'NSPrivacyCollectedDataTypeTracking</key>\\s*<true/>',
      ).hasMatch(privacidad),
      isFalse,
    );
  });

  testWidgets('en iPhone, quien entraba con Google sabe crear su contraseña', (
    tester,
  ) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    await tester.pumpWidget(
      const ProviderScope(child: MaterialApp(home: LoginScreen())),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('google-en-iphone')), findsOneWidget);
    expect(find.textContaining('¿Olvidaste tu contraseña?'), findsWidgets);
    debugDefaultTargetPlatformOverride = null;
  });

  testWidgets('en Android no hace falta ese aviso', (tester) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    await tester.pumpWidget(
      const ProviderScope(child: MaterialApp(home: LoginScreen())),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('google-en-iphone')), findsNothing);
    debugDefaultTargetPlatformOverride = null;
  });

  test('el widget de inicio es un target del proyecto, con lo de su sabor', () {
    final proyecto = _leer('ios/Runner.xcodeproj/project.pbxproj');
    expect(
      proyecto,
      contains('productType = "com.apple.product-type.app-extension";'),
    );
    expect(proyecto, contains('ProximoWidget.swift in Sources'));
    // Runner lo copia (Embed Foundation Extensions, antes de «Thin Binary») y
    // depende de él.
    final fases = RegExp(
      r'/\* Embed Foundation Extensions \*/,\s*3B06AD1E1E4923F5004D2608 /\* Thin Binary \*/',
    );
    expect(fases.hasMatch(proyecto), isTrue);
    expect(proyecto, contains('dstSubfolderSpec = 13;'));
    // Cada configuración del widget: su id, su App Group y su sabor.
    for (final sabor in ['agendauno', 'turnouno']) {
      expect(
        proyecto,
        contains('PRODUCT_BUNDLE_IDENTIFIER = com.$sabor.app.ProximoWidget;'),
      );
    }
    final configuraciones = RegExp(
      r'APP_GROUP_ID = group\.com\.(\w+)\.app;\s*APPLICATION_EXTENSION_API_ONLY = YES;[^}]*?FLAVOR = (\w+);[^}]*?PRODUCT_BUNDLE_IDENTIFIER = com\.(\w+)\.app\.ProximoWidget;',
    ).allMatches(proyecto).toList();
    expect(configuraciones, hasLength(9));
    for (final c in configuraciones) {
      expect({c.group(1), c.group(2), c.group(3)}, hasLength(1));
    }

    final plist = _leer('ios/ProximoWidget/Info.plist');
    expect(plist, contains('<string>com.apple.widgetkit-extension</string>'));
    expect(plist, contains(r'<key>AppGroupId</key>'));
    expect(plist, contains(r'<string>$(APP_GROUP_ID)</string>'));
    expect(plist, contains(r'<string>$(FLAVOR)</string>'));
    for (final ruta in [
      'ios/ProximoWidget/ProximoWidget.entitlements',
      'ios/Runner/Runner.entitlements',
    ]) {
      final permisos = _leer(ruta);
      expect(permisos, contains('com.apple.security.application-groups'));
      expect(permisos, contains(r'<string>$(APP_GROUP_ID)</string>'));
    }
  });
}
