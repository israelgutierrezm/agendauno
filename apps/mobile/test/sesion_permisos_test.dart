import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

void main() {
  test(
    'la sesión trae roles y permisos: solo ofrece lo que el servidor permite',
    () {
      final instructor = Sesion.desdeJson('estudio', '1|x', {
        'nombre': 'Beto',
        'rol': 'instructor',
        'roles': ['instructor'],
        'permisos': ['agenda.ver', 'asistencia.marcar'],
      });

      expect(instructor.puede('asistencia.marcar'), isTrue);
      expect(instructor.puede('ordenes.gestionar'), isFalse);
    },
  );

  test(
    'el propietario puede todo',
    () {
      final dueno = Sesion.desdeJson('estudio', '1|x', {
        'nombre': 'Dueña',
        'rol': 'propietario',
        'roles': ['propietario', 'miembro'],
        'permisos': ['*'],
      });

      expect(dueno.puede('pagos.reembolsar'), isTrue);
    },
  );

  test(
    'los permisos y el perfil sobreviven al almacén y se actualizan con el servidor',
    () {
      const sesion = Sesion(
        slug: 'estudio',
        bearer: '1|x',
        nombre: 'Ana López',
        rol: 'miembro',
        roles: ['miembro'],
        permisos: ['formularios.responder'],
        nombrePila: 'Ana',
        primerApellido: 'López',
        tieneContrasena: false,
      );

      final restaurada = Sesion.desdeAlmacen(sesion.aJson())!;
      expect(restaurada.puede('formularios.responder'), isTrue);
      expect(restaurada.primerApellido, 'López');
      expect(restaurada.tieneContrasena, isFalse);

      final editada = restaurada.conUsuario({
        'nombre': 'Ana Ruiz',
        'nombre_pila': 'Ana',
        'primer_apellido': 'Ruiz',
        'tiene_contrasena': true,
      });
      expect(editada.nombre, 'Ana Ruiz');
      expect(editada.primerApellido, 'Ruiz');
      expect(editada.tieneContrasena, isTrue);
      // Sin roles ni permisos en la respuesta, se conservan los que tenía.
      expect(editada.puede('formularios.responder'), isTrue);
    },
  );
}
