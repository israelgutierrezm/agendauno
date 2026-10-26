import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/notificaciones/application/push_controller.dart';

/// Push en las tiendas: en iPhone se espera el token de APNs (con reintentos) antes
/// de pedir el de Firebase, y Android publica las notificaciones en el canal propio
/// de la app, no en el genérico.

void main() {
  test('espera el token de APNs con pausas que crecen', () async {
    final pausas = <Duration>[];
    var lecturas = 0;
    final token = await PushController.esperarApns(
      () async => ++lecturas < 3 ? null : 'apns-123',
      dormir: (d) async => pausas.add(d),
    );

    expect(token, 'apns-123');
    expect(lecturas, 3);
    expect(pausas, const [
      Duration(milliseconds: 500),
      Duration(milliseconds: 1000),
    ]);
  });

  test('si APNs no responde, no se pide el de Firebase (null)', () async {
    var lecturas = 0;
    final token = await PushController.esperarApns(
      () async {
        lecturas++;
        throw Exception('apns-token-not-set');
      },
      intentos: 4,
      dormir: (_) async {},
    );

    expect(token, isNull);
    expect(lecturas, 4);
  });

  test('Android: el canal propio de los avisos', () {
    final manifiesto = File(
      'android/app/src/main/AndroidManifest.xml',
    ).readAsStringSync();
    expect(
      manifiesto,
      contains('com.google.firebase.messaging.default_notification_channel_id'),
    );
    expect(manifiesto, contains('@string/canal_avisos_id'));
    final avisos = File(
      'android/app/src/main/res/values/avisos.xml',
    ).readAsStringSync();
    expect(avisos, contains('name="canal_avisos_id"'));
    expect(avisos, contains('name="canal_avisos_nombre"'));
    final actividad = File(
      'android/app/src/main/kotlin/com/agendauno/app/MainActivity.kt',
    ).readAsStringSync();
    expect(actividad, contains('createNotificationChannel'));
    expect(actividad, contains('R.string.canal_avisos_id'));
  });
}
