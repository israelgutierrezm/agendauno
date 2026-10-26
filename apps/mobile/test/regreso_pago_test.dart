import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/config/app_config.dart';
import 'package:agendauno/core/enlaces/regreso_pago.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';

/// El regreso a la app después de pagar en el navegador: cada app tiene su esquema
/// (`agendauno`, `turnouno`, el de la marca blanca) declarado en Android e iOS, el
/// checkout lo manda como `volver_app`, y al abrirse con `<esquema>://pagos?ref=…` la
/// app va a Pagos (el enlace no llega al Navigator). Sin red real.

class _ApiCobro implements HttpClientAdapter {
  RequestOptions? pedido;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedido = options;
    return ResponseBody.fromString(
      jsonEncode({
        'data': {
          'checkout': {'url': 'https://pasarela.example/pagar'},
        },
      }),
      201,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test('el esquema de la app: el de su producto, como lo acepta la API', () {
    expect(
      AppConfig.esquemaApp,
      AppConfig.marcaBlanca
          ? AppConfig.idAndroid.toLowerCase().replaceAll('_', '-')
          : AppConfig.producto.clave,
    );
    expect(
      RegExp(r'^[a-z][a-z0-9.+-]{2,40}$').hasMatch(AppConfig.esquemaApp),
      isTrue,
    );
  });

  test('entiende el regreso del pago como URL o como ruta', () {
    const esquema = 'agendauno';
    final completo = RegresoDePago.de(
      Uri.parse('agendauno://pagos?ref=pago_123&pago=exito'),
      esquema: esquema,
    )!;
    expect(completo.ref, 'pago_123');
    expect(completo.estado, 'exito');
    expect(completo.mensaje, contains('Recibimos tu pago'));

    expect(
      RegresoDePago.de(
        Uri.parse('/pagos?ref=x&pago=pendiente'),
        esquema: esquema,
      )?.mensaje,
      contains('en proceso'),
    );
    expect(
      RegresoDePago.de(
        Uri.parse('/?ref=x&pago=cancelado'),
        esquema: esquema,
      )?.estado,
      'cancelado',
    );
    // Otro esquema, otra ruta o una ruta sin nada del pago: no es el regreso.
    expect(
      RegresoDePago.de(Uri.parse('turnouno://pagos?ref=x'), esquema: esquema),
      isNull,
    );
    expect(RegresoDePago.de(Uri.parse('/'), esquema: esquema), isNull);
    expect(
      RegresoDePago.de(Uri.parse('agendauno://otra?ref=x'), esquema: esquema),
      isNull,
    );
  });

  testWidgets('el enlace se atiende antes que el Navigator', (tester) async {
    final recibidos = <RegresoDePago>[];
    final enlaces = EnlacesDeLaApp(recibidos.add);
    WidgetsBinding.instance.addObserver(enlaces);
    addTearDown(() => WidgetsBinding.instance.removeObserver(enlaces));

    final atendido = await enlaces.didPushRouteInformation(
      RouteInformation(
        uri: Uri.parse('${AppConfig.esquemaApp}://pagos?ref=p9&pago=exito'),
      ),
    );
    final otro = await enlaces.didPushRouteInformation(
      RouteInformation(uri: Uri.parse('/mi-cuenta')),
    );

    expect(atendido, isTrue);
    expect(otro, isFalse);
    expect(recibidos.single.ref, 'p9');
  });

  test('el checkout dice a qué app volver', () async {
    final api = _ApiCobro();
    final repo = CuentaRepository(Dio()..httpClientAdapter = api, 'demo');

    await repo.pagarOrden('o1');

    expect(api.pedido?.path, '/api/v1/app/demo/mi/ordenes/o1/cobrar');
    expect(api.pedido?.data['volver_app'], AppConfig.esquemaApp);
  });

  test('Android e iOS declaran el esquema de cada app', () {
    final manifiesto = File(
      'android/app/src/main/AndroidManifest.xml',
    ).readAsStringSync();
    expect(manifiesto, contains(r'android:scheme="${esquemaApp}"'));
    expect(manifiesto, contains('android:host="pagos"'));
    final gradle = File('android/app/build.gradle.kts').readAsStringSync();
    expect(gradle, contains('manifestPlaceholders["esquemaApp"] = "turnouno"'));
    expect(
      gradle,
      contains(
        'manifestPlaceholders["esquemaApp"] = esquemaMarcaBlanca.ifEmpty { "agendauno" }',
      ),
    );

    final plist = File('ios/Runner/Info.plist').readAsStringSync();
    expect(plist, contains(r'<string>$(APP_URL_SCHEME)</string>'));
    // Cada configuración del target Runner con el esquema de su sabor.
    final proyecto = File(
      'ios/Runner.xcodeproj/project.pbxproj',
    ).readAsStringSync();
    final grupos = RegExp(
      r'APP_GROUP_ID = group\.com\.(agendauno|turnouno)\.app;\s*APP_URL_SCHEME = (\w+);',
    ).allMatches(proyecto).toList();
    expect(grupos, hasLength(9));
    for (final g in grupos) {
      expect(g.group(2), g.group(1));
    }
  });
}
