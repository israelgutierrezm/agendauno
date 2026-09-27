import 'package:flutter_test/flutter_test.dart';
import 'package:turnouno_mobile/features/auth/data/sesion.dart';

void main() {
  test('usa los plurales que manda el servidor y los conserva al guardar', () {
    final t = Terminologia.desdeJson({
      'sesion': 'Lección',
      'sesiones': 'Lecciones',
      'miembro': 'Cliente',
      'miembros': 'Clientes',
      'instructor': 'Coach',
      'instructores': 'Coaches',
    });

    expect(t.sesiones, 'Lecciones');
    expect(t.instructores, 'Coaches');
    expect(Terminologia.desdeJson(t.aJson()).sesiones, 'Lecciones');
  });

  test('sin plurales del servidor los arma', () {
    final t = Terminologia.desdeJson({'sesion': 'Cita', 'miembro': 'Cliente', 'instructor': 'Barbero'});

    expect(t.sesiones, 'Citas');
    expect(t.miembros, 'Clientes');
    expect(const Terminologia().instructores, 'Instructores');
  });
}
