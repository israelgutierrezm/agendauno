import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/config/app_config.dart';
import 'package:agendauno/core/config/firebase_config.dart';
import 'package:agendauno/core/config/producto_app.dart';
import 'package:agendauno/core/legales/enlaces_legales.dart';
import 'package:agendauno/core/network/auth_token.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/network/participante_en_uso.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/core/theme/tema_agendauno.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_screen.dart';
import 'package:agendauno/features/widget_inicio/application/widget_inicio.dart';
import 'package:agendauno/main.dart' show colorDeLaApp;

/// Lo que piden las tiendas y la marca blanca (ADR 0208): el token solo va a la API
/// de esta app, Firebase por plataforma, los avisos legales a la mano, el color de
/// la marca legible y el vocabulario de cada producto. Corre igual compilada como
/// AgendaUno o TurnoUno. Datos sintéticos.

/// API falsa: guarda lo que se le pidió y responde según la ruta.
class _Api implements HttpClientAdapter {
  _Api([this.responder]);

  final (int, Object) Function(RequestOptions)? responder;
  final List<RequestOptions> peticiones = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    final (estado, cuerpo) =
        responder?.call(options) ?? (200, const {'data': <String, Object>{}});
    return ResponseBody.fromString(
      jsonEncode(cuerpo),
      estado,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  group('el token de la sesión solo va a la API de esta app', () {
    test('a la API sí; al otro producto y a otro sitio, no', () async {
      final c = ProviderContainer(
        overrides: [tokenInicialProvider.overrideWithValue('token-1')],
      );
      addTearDown(c.dispose);
      c.read(participanteEnUsoProvider.notifier).elegir('persona-1');
      final api = _Api();
      final dio = c.read(dioProvider)..httpClientAdapter = api;

      await dio.get<Object>('/api/v1/app/demo/mi/cuenta');
      await dio.get<Object>(
        '${ProductoApp.actual.otro.urlProduccion}/api/v1/app/demo/mi/cuenta',
      );
      await dio.get<Object>('https://otro.example/api/v1/app/demo/mi/cuenta');

      final [propia, otroProducto, otroSitio] = api.peticiones;
      expect(propia.headers['Authorization'], 'Bearer token-1');
      expect(propia.headers['X-Participante'], 'persona-1');
      for (final ajena in [otroProducto, otroSitio]) {
        expect(ajena.headers.containsKey('Authorization'), isFalse);
        expect(ajena.headers.containsKey('X-Participante'), isFalse);
        // Sí dice de qué app viene (así la otra API sabe que no es suya).
        expect(ajena.headers['X-App-Producto'], ProductoApp.actual.clave);
      }
    });

    test('la API es el mismo esquema, host y puerto', () {
      final api = Uri.parse(AppConfig.apiBaseUrl);
      expect(AppConfig.esDeLaApi(api.resolve('/api/v1/app/x/yo')), isTrue);
      expect(
        AppConfig.esDeLaApi(api.replace(host: 'api.${api.host}.example')),
        isFalse,
      );
      expect(AppConfig.esDeLaApi(api.replace(port: api.port + 1)), isFalse);
      expect(
        AppConfig.esDeLaApi(
          api.replace(scheme: api.scheme == 'https' ? 'http' : 'https'),
        ),
        isFalse,
      );
    });
  });

  group('Firebase por plataforma', () {
    const valores = ValoresFirebase(
      apiKey: 'llave',
      appIdAndroid: '1:1:android:a',
      appIdIos: '1:1:ios:i',
      senderId: '1',
      proyecto: 'proyecto',
      bundleIos: 'com.agendauno.app',
    );

    test('cada plataforma toma su id; la llave general vale para las dos', () {
      final android = FirebaseConfig.opcionesPara(
        TargetPlatform.android,
        valores: valores,
      )!;
      final ios = FirebaseConfig.opcionesPara(
        TargetPlatform.iOS,
        valores: valores,
      )!;

      expect(android.appId, '1:1:android:a');
      expect(ios.appId, '1:1:ios:i');
      expect([android.apiKey, ios.apiKey], ['llave', 'llave']);
      expect(android.iosBundleId, isNull);
      expect(ios.iosBundleId, 'com.agendauno.app');
    });

    test('con la llave propia de iOS y las claves de antes', () {
      final ios = FirebaseConfig.opcionesPara(
        TargetPlatform.iOS,
        valores: const ValoresFirebase(
          apiKey: 'llave',
          apiKeyIos: 'llave-ios',
          appId: '1:1:general:g',
          senderId: '1',
          proyecto: 'proyecto',
        ),
      )!;
      expect(ios.apiKey, 'llave-ios');
      // Sin FIREBASE_IOS_APP_ID, el FIREBASE_APP_ID de siempre.
      expect(ios.appId, '1:1:general:g');
    });

    test('sin su id (o sin proyecto), sin push', () {
      expect(
        FirebaseConfig.opcionesPara(
          TargetPlatform.iOS,
          valores: const ValoresFirebase(
            apiKey: 'llave',
            appIdAndroid: '1:1:android:a',
            senderId: '1',
            proyecto: 'proyecto',
          ),
        ),
        isNull,
      );
      expect(
        FirebaseConfig.opcionesPara(
          TargetPlatform.android,
          valores: const ValoresFirebase(),
        ),
        isNull,
      );
    });
  });

  group('aviso de privacidad y términos', () {
    test('en la web de esta app y, con el negocio, el suyo', () {
      final web = AppConfig.webBaseUrl.replaceFirst(RegExp(r'/+$'), '');
      final docs = DocumentoLegal.de(negocio: 'fluo', nombreNegocio: 'Fluō');

      expect(docs.map((d) => d.url.toString()), [
        '$web/aviso-de-privacidad',
        '$web/terminos',
        '$web/estudio/fluo/aviso-de-privacidad',
      ]);
      expect(docs.last.titulo, 'Aviso de privacidad de Fluō');
      expect(DocumentoLegal.de(), hasLength(2));
    });

    testWidgets('el acceso los muestra; la marca blanca, también el suyo', (
      tester,
    ) async {
      await tester.pumpWidget(
        const ProviderScope(child: MaterialApp(home: LoginScreen())),
      );
      expect(find.byKey(const Key('enlaces-legales')), findsOneWidget);
      expect(
        find.text('Aviso de privacidad de ${ProductoApp.actual.nombre}'),
        findsOneWidget,
      );
      expect(find.text('Términos y condiciones'), findsOneWidget);

      await tester.pumpWidget(
        const ProviderScope(
          child: MaterialApp(
            home: LoginScreen(negocioFijo: 'fluo', nombreApp: 'Fluō Pilates'),
          ),
        ),
      );
      expect(find.text('Aviso de privacidad de Fluō Pilates'), findsOneWidget);
    });
  });

  testWidgets('marca blanca: si su negocio no está, no pide revisar la dirección', (
    tester,
  ) async {
    FlutterSecureStorage.setMockInitialValues({});
    final c = ProviderContainer(
      overrides: [almacenSesionProvider.overrideWithValue(AlmacenSesion())],
    );
    addTearDown(c.dispose);
    c.read(dioProvider).httpClientAdapter = _Api(
      (_) => (404, const {'message': 'Estudio no encontrado.'}),
    );
    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: c,
        child: const MaterialApp(
          home: LoginScreen(negocioFijo: 'fluo', nombreApp: 'Fluō Pilates'),
        ),
      ),
    );
    await tester.enterText(find.widgetWithText(TextField, 'Correo'), 'a@b.mx');
    await tester.enterText(find.widgetWithText(TextField, 'Contraseña'), 'x');
    await tester.tap(find.widgetWithText(FilledButton, 'Entrar'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Esta app no está disponible por ahora. Contacta a Fluō Pilates.',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('Revisa la dirección'), findsNothing);
  });

  group('el color de la marca blanca', () {
    test('se lee de la sesión: login y /yo, y se guarda', () {
      final s = Sesion.desdeJson('fluo', 't', {'rol': 'miembro'}, {
        'color_marca': '#0F766E',
      });
      expect(s.colorMarca, '#0F766E');
      expect(Sesion.desdeAlmacen(s.aJson())!.colorMarca, '#0F766E');
      // Un valor que no es color no se toma; /yo con otro color lo cambia.
      expect(
        Sesion.desdeJson('fluo', 't', {}, {'color_marca': 'rojo'}).colorMarca,
        isNull,
      );
      expect(
        s.conUsuario(const {}, estudio: {'color_marca': '#112233'}).colorMarca,
        '#112233',
      );
      expect(s.conUsuario(const {}).colorMarca, '#0F766E');
    });

    test('solo en la marca blanca; antes de entrar, el de su archivo', () {
      const sesion = Sesion(
        slug: 'fluo',
        bearer: 't',
        nombre: 'Ana',
        rol: 'miembro',
        colorMarca: '#0F766E',
      );
      expect(colorDeLaApp(sesion, marcaBlanca: false), isNull);
      expect(colorDeLaApp(sesion, marcaBlanca: true), const Color(0xFF0F766E));
      expect(
        colorDeLaApp(null, marcaBlanca: true, porOmision: '#abc'),
        const Color(0xFFAABBCC),
      );
      expect(colorDeLaApp(null, marcaBlanca: true, porOmision: ''), isNull);
    });

    test('se oscurece o se aclara hasta leerse (4.5:1), sin cambiar de tono', () {
      const amarillo = Color(0xFFFFEB3B);
      const marino = Color(0xFF1A237E);
      final claro = ColoresAgendaUno.claros.conMarca(amarillo);
      final oscuro = ColoresAgendaUno.oscuros.conMarca(marino);

      expect(
        ColoresAgendaUno.contraste(claro.acento, claro.superficie),
        greaterThanOrEqualTo(4.5),
      );
      expect(
        ColoresAgendaUno.contraste(claro.acento, claro.acentoContraste),
        greaterThanOrEqualTo(4.5),
      );
      expect(
        ColoresAgendaUno.contraste(oscuro.acento, oscuro.superficie),
        greaterThanOrEqualTo(4.5),
      );
      expect(
        ColoresAgendaUno.contraste(oscuro.acento, oscuro.acentoContraste),
        greaterThanOrEqualTo(4.5),
      );
      expect(
        HSLColor.fromColor(claro.acento).hue,
        closeTo(HSLColor.fromColor(amarillo).hue, 1),
      );
      // Un color que ya se lee no cambia; el tema lo usa como su primario.
      const verde = Color(0xFF0F766E);
      expect(ColoresAgendaUno.claros.conMarca(verde).acento, verde);
      expect(TemaAgendaUno.claro(marca: verde).colorScheme.primary, verde);
      expect(TemaAgendaUno.claro().colorScheme.primary, ColoresAgendaUno.claros.acento);
    });

    test('un color se lee con o sin #, corto o largo', () {
      expect(ColoresAgendaUno.colorDe('#0F766E'), const Color(0xFF0F766E));
      expect(ColoresAgendaUno.colorDe('0f766e'), const Color(0xFF0F766E));
      expect(ColoresAgendaUno.colorDe('#fff'), const Color(0xFFFFFFFF));
      expect(ColoresAgendaUno.colorDe('azul'), isNull);
      expect(ColoresAgendaUno.colorDe(null), isNull);
    });
  });

  test('el App Group del widget sale de APP_GROUP_ID o del id de la app', () {
    expect(AlmacenHomeWidget.grupoApp, AppConfig.appGroupId);
    if (!const bool.hasEnvironment('APP_GROUP_ID')) {
      expect(AppConfig.appGroupId, 'group.${ProductoApp.actual.idAndroid}');
    }
  });

  testWidgets('en citas se cancela una cita, no una reserva', (tester) async {
    const sesion = Sesion(
      slug: 'barberia',
      bearer: 't',
      nombre: 'Ana',
      rol: 'miembro',
      modalidad: Modalidad.citas,
    );
    final api = _Api(
      (o) => o.uri.path.endsWith('/cancelacion')
          ? (
              200,
              const {
                'data': {'cancelable': true, 'mensaje': 'Sin costo.'},
              },
            )
          : (200, const {'data': <String, Object>{}}),
    );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(sesion),
          cuentaRepositoryProvider.overrideWithValue(
            CuentaRepository(Dio()..httpClientAdapter = api, 'barberia'),
          ),
          cuentaProvider.overrideWith(_CuentaFalsa.new),
        ],
        child: MaterialApp(
          home: Scaffold(
            body: Consumer(
              builder: (context, ref, _) => TextButton(
                onPressed: () => confirmarCancelacion(context, ref, 'r1'),
                child: const Text('Abrir'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Abrir'));
    await tester.pumpAndSettle();
    expect(find.text('Cancelar cita'), findsOneWidget);
    expect(find.text('Cancelar reserva'), findsNothing);

    await tester.tap(find.text('Sí, cancelar'));
    await tester.pumpAndSettle();
    expect(find.text('Cita cancelada.'), findsOneWidget);
  });
}

class _CuentaFalsa extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);

  @override
  Future<void> cancelar(String reservaId) async {}
}
