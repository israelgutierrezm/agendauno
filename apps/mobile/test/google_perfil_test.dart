import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/google/google_auth.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';
import 'package:agendauno/features/perfil/presentation/perfil_screen.dart';

/// Entrar con Google en la app (ADR 0093): se conecta desde Mi perfil y solo
/// entra quien lo conectó. Google falso; datos sintéticos.

class _GoogleFalso implements GoogleAuth {
  _GoogleFalso({this.disponible = true});

  @override
  final bool disponible;

  @override
  Future<String?> idToken() async => 'id-token-falso';
}

class _Sesion extends SesionController {
  _Sesion(this.conectado);

  final bool conectado;
  final tokens = <String>[];
  var quitado = false;

  @override
  Sesion? build() => Sesion.desdeJson('estudio-a', '1|x', {
    'nombre': 'Ana',
    'rol': 'miembro',
    'roles': ['miembro'],
    'google_conectado': conectado,
  });

  @override
  Future<void> conectarGoogle(String idToken) async {
    tokens.add(idToken);
    state = state!.conUsuario({'google_conectado': true});
  }

  @override
  Future<void> desconectarGoogle() async {
    quitado = true;
    state = state!.conUsuario({'google_conectado': false});
  }
}

Future<ProviderContainer> _montar(
  WidgetTester tester,
  Widget pantalla, {
  bool conectado = false,
  bool disponible = true,
}) async {
  // Pantalla alta: todo el perfil a la vista (la lista no construye lo de abajo).
  tester.view.physicalSize = const Size(800, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final contenedor = ProviderContainer(
    overrides: [
      sesionProvider.overrideWith(() => _Sesion(conectado)),
      googleAuthProvider.overrideWithValue(
        _GoogleFalso(disponible: disponible),
      ),
    ],
  );
  addTearDown(contenedor.dispose);
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: contenedor,
      child: MaterialApp(home: pantalla),
    ),
  );
  return contenedor;
}

void main() {
  test('la sesión recuerda si Google está conectado', () {
    final sesion = Sesion.desdeJson('e', '1|x', {'google_conectado': true});
    expect(sesion.googleConectado, isTrue);
    expect(Sesion.desdeAlmacen(sesion.aJson())!.googleConectado, isTrue);
    expect(sesion.conUsuario(const {'nombre': 'Ana'}).googleConectado, isTrue);
    expect(
      sesion.conUsuario(const {'google_conectado': false}).googleConectado,
      isFalse,
    );
  });

  testWidgets('desde Mi perfil se conecta Google con su ID token', (
    tester,
  ) async {
    final contenedor = await _montar(tester, const PerfilScreen());

    await tester.tap(find.byKey(const Key('conectar-google')));
    await tester.pumpAndSettle();

    final controlador = contenedor.read(sesionProvider.notifier) as _Sesion;
    expect(controlador.tokens, ['id-token-falso']);
    expect(find.byKey(const Key('quitar-google')), findsOneWidget);
    expect(find.text('Listo: ya puedes entrar con Google.'), findsOneWidget);
  });

  testWidgets('con Google conectado se puede quitar', (tester) async {
    final contenedor = await _montar(
      tester,
      const PerfilScreen(),
      conectado: true,
    );

    await tester.tap(find.byKey(const Key('quitar-google')));
    await tester.pumpAndSettle();

    final controlador = contenedor.read(sesionProvider.notifier) as _Sesion;
    expect(controlador.quitado, isTrue);
    expect(find.byKey(const Key('conectar-google')), findsOneWidget);
  });

  testWidgets('sin Google configurado no se ofrece en el perfil ni al entrar', (
    tester,
  ) async {
    await _montar(tester, const PerfilScreen(), disponible: false);
    expect(find.byKey(const Key('google')), findsNothing);

    await _montar(tester, const LoginScreen(), disponible: false);
    expect(find.byKey(const Key('entrar-google')), findsNothing);
  });

  testWidgets('con Google configurado, al entrar se ofrece Google', (
    tester,
  ) async {
    await _montar(tester, const LoginScreen());
    expect(find.byKey(const Key('entrar-google')), findsOneWidget);
  });
}
