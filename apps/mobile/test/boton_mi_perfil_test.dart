import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/perfil/presentation/boton_mi_perfil.dart';

/// «Mi perfil» en la barra de arriba: con más de un rol lleva el punto que late
/// (ahí está «Cambiar de rol»); con uno solo, no. Datos sintéticos.

class _Sesion extends SesionController {
  _Sesion(this.roles);

  final List<String> roles;

  @override
  Sesion? build() => Sesion.desdeJson('demo', '1|x', {
    'nombre': 'Ana',
    'rol': roles.first,
    'roles': roles,
    'roles_disponibles': [
      for (final r in roles)
        {'clave': r, 'faceta': r == 'miembro' ? 'miembro' : 'equipo'},
    ],
    'permisos': const <String>[],
  });
}

Future<void> montar(WidgetTester tester, List<String> roles) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [sesionProvider.overrideWith(() => _Sesion(roles))],
      child: MaterialApp(
        home: Scaffold(appBar: AppBar(actions: const [BotonMiPerfil()])),
      ),
    ),
  );
  // El punto late sin parar: un solo cuadro, sin esperar a que termine.
  await tester.pump();
}

void main() {
  testWidgets('con varios roles, «Mi perfil» lleva el punto', (tester) async {
    await montar(tester, ['propietario', 'miembro']);
    expect(find.byKey(const Key('punto-rol-perfil')), findsOneWidget);
    expect(find.byTooltip('Mi perfil (puedes cambiar de rol)'), findsOneWidget);
  });

  testWidgets('con un solo rol, sin punto', (tester) async {
    await montar(tester, ['admin']);
    expect(find.byKey(const Key('punto-rol-perfil')), findsNothing);
    expect(find.byTooltip('Mi perfil'), findsOneWidget);
  });
}
