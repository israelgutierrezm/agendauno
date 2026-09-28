import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:turnouno_mobile/features/auth/application/sesion_controller.dart';
import 'package:turnouno_mobile/features/auth/data/sesion.dart';
import 'package:turnouno_mobile/features/auth/presentation/elegir_rol_screen.dart';

/// Rol activo: quien tiene varios roles entra con uno (lo elige al entrar) y la app
/// muestra solo lo de ese rol. Datos sintéticos.

Map<String, dynamic> usuario(String rol) => {
  'nombre': 'Ana',
  'rol': rol,
  'roles': ['instructor', 'miembro'],
  'roles_disponibles': [
    {'clave': 'instructor', 'faceta': 'instructor'},
    {'clave': 'miembro', 'faceta': 'miembro'},
  ],
  'permisos': rol == 'miembro' ? ['formularios.responder'] : ['agenda.ver'],
};

class _Sesion extends SesionController {
  final elegidos = <String>[];

  @override
  Sesion? build() =>
      Sesion.desdeJson('barberia', '1|x', usuario('instructor'), {
        'nombre': 'Barbería Uno',
        'perfil_config': {
          'modalidad': 'citas',
          'terminologia': {
            'sesion': 'Cita',
            'miembro': 'Cliente',
            'instructor': 'Barbero',
          },
        },
      }).conUsuario(const {}, eligiendoRol: true);

  @override
  Future<void> cambiarRol(String rol) async {
    elegidos.add(rol);
    state = state!.conUsuario(usuario(rol), eligiendoRol: false);
  }
}

void main() {
  test('la sesión sabe con qué rol entró y qué le toca', () {
    final comoInstructor = Sesion.desdeJson('e', '1|x', usuario('instructor'));
    expect(comoInstructor.tieneVariosRoles, isTrue);
    expect(comoInstructor.esInstructorAcotado, isTrue);
    expect(comoInstructor.esMiembro, isFalse);

    final comoMiembro = comoInstructor.conUsuario(usuario('miembro'));
    expect(comoMiembro.esMiembro, isTrue);
    expect(comoMiembro.esInstructorAcotado, isFalse);
    expect(comoMiembro.puede('agenda.ver'), isFalse);

    // Administra y además imparte: entró como admin, ve lo del negocio.
    final admin = Sesion.desdeJson('e', '1|x', {
      'rol': 'admin',
      'roles': ['admin', 'instructor'],
    });
    expect(admin.esInstructorAcotado, isFalse);
    expect(admin.esMiembro, isFalse);
  });

  test(
    'los roles disponibles se guardan en el teléfono; la elección pendiente no',
    () {
      final sesion = Sesion.desdeJson(
        'e',
        '1|x',
        usuario('miembro'),
      ).conUsuario(const {}, eligiendoRol: true);

      final restaurada = Sesion.desdeAlmacen(sesion.aJson())!;
      expect(restaurada.rolesDisponibles.map((r) => r.clave), [
        'instructor',
        'miembro',
      ]);
      expect(restaurada.rol, 'miembro');
      expect(restaurada.eligiendoRol, isFalse);
    },
  );

  testWidgets(
    '«¿Cómo quieres entrar?» nombra los roles como el negocio y entra con el elegido',
    (tester) async {
      final contenedor = ProviderContainer(
        overrides: [sesionProvider.overrideWith(_Sesion.new)],
      );
      addTearDown(contenedor.dispose);

      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: contenedor,
          child: const MaterialApp(home: ElegirRolScreen()),
        ),
      );

      expect(find.text('¿Cómo quieres entrar?'), findsOneWidget);
      expect(find.text('Barbero'), findsOneWidget);
      expect(find.text('Cliente'), findsOneWidget);
      // El de la última vez viene marcado.
      expect(
        find.descendant(
          of: find.byKey(const Key('rol-instructor')),
          matching: find.text('La última vez'),
        ),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('rol-miembro')));
      await tester.pumpAndSettle();

      final controlador = contenedor.read(sesionProvider.notifier) as _Sesion;
      expect(controlador.elegidos, ['miembro']);
      final sesion = contenedor.read(sesionProvider)!;
      expect(sesion.eligiendoRol, isFalse);
      expect(sesion.esMiembro, isTrue);
    },
  );
}
