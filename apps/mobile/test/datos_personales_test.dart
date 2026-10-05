import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/google/google_auth.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/perfil/presentation/perfil_screen.dart';

/// Fecha de nacimiento y género (opcionales) en Mi perfil de la app: solo con ficha
/// de cliente o alumno, y se mandan al guardar. Datos sintéticos.

class _SinGoogle implements GoogleAuth {
  @override
  bool get disponible => false;

  @override
  Future<String?> idToken() async => null;
}

class _Sesion extends SesionController {
  _Sesion({required this.conFicha});

  final bool conFicha;
  Map<String, Object?>? guardado;

  @override
  Sesion? build() => Sesion.desdeJson('estudio-a', '1|x', {
    'nombre': 'Ana',
    'nombre_pila': 'Ana',
    'rol': 'miembro',
    'roles': ['miembro'],
    'tiene_ficha': conFicha,
    'fecha_nacimiento': conFicha ? '1994-03-14' : null,
    'genero': conFicha ? 'mujer' : null,
  });

  @override
  Future<void> guardarPerfil({
    required String nombre,
    String? primerApellido,
    String? segundoApellido,
    String? celular,
    String? fechaNacimiento,
    String? genero,
    bool conCelular = false,
  }) async {
    guardado = {
      'fecha_nacimiento': fechaNacimiento,
      'genero': genero,
      'con_ficha': conCelular,
    };
  }
}

Future<_Sesion> _montar(WidgetTester tester, {required bool conFicha}) async {
  tester.view.physicalSize = const Size(800, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final sesion = _Sesion(conFicha: conFicha);
  final contenedor = ProviderContainer(
    overrides: [
      sesionProvider.overrideWith(() => sesion),
      googleAuthProvider.overrideWithValue(_SinGoogle()),
    ],
  );
  addTearDown(contenedor.dispose);
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: contenedor,
      child: const MaterialApp(home: PerfilScreen()),
    ),
  );
  return sesion;
}

void main() {
  test('la sesión guarda la fecha de nacimiento y el género de su ficha', () {
    final s = Sesion.desdeJson('estudio-a', '1|x', {
      'nombre': 'Ana',
      'fecha_nacimiento': '1994-03-14',
      'genero': 'no_binario',
    });
    final r = Sesion.desdeAlmacen(s.aJson())!;
    expect(r.fechaNacimiento, '1994-03-14');
    expect(r.genero, 'no_binario');
    expect(r.conUsuario({'genero': null}).genero, isNull);
  });

  testWidgets('con ficha muestra y guarda la fecha y el género', (
    tester,
  ) async {
    final sesion = await _montar(tester, conFicha: true);
    expect(find.text('14 de marzo de 1994'), findsOneWidget);
    expect(find.byKey(const Key('genero')), findsOneWidget);
    expect(find.text('Mujer'), findsOneWidget);

    await tester.tap(find.text('Guardar'));
    await tester.pumpAndSettle();
    expect(sesion.guardado, {
      'fecha_nacimiento': '1994-03-14',
      'genero': 'mujer',
      'con_ficha': true,
    });
  });

  testWidgets('sin ficha (personal) no los pide', (tester) async {
    await _montar(tester, conFicha: false);
    expect(find.byKey(const Key('fecha-nacimiento')), findsNothing);
    expect(find.byKey(const Key('genero')), findsNothing);
  });
}
