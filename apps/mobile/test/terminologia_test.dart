import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/corte_planes.dart';

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
    final t = Terminologia.desdeJson({
      'sesion': 'Cita',
      'miembro': 'Cliente',
      'instructor': 'Barbero',
    });

    expect(t.sesiones, 'Citas');
    expect(t.miembros, 'Clientes');
    expect(const Terminologia().instructores, 'Instructores');
  });

  test('sin plurales del servidor, las agudas pierden el acento', () {
    expect(const Terminologia(sesion: 'Lección').sesiones, 'Lecciones');
    expect(const Terminologia(sesion: 'Sesión').sesiones, 'Sesiones');
    expect(const Terminologia(instructor: 'Coach').instructores, 'Coaches');
  });

  test('un plan agotado se dice con la terminología del negocio', () {
    final plan = PlanCorte.desdeJson({
      'id': 'p1',
      'derecho_id': 'd1',
      'estado': 'agotado',
      'unidades': {'disponibles': 0},
    });
    final barberia = Terminologia.desdeJson({
      'sesion': 'Cita',
      'sesiones': 'Citas',
      'miembro': 'Cliente',
      'instructor': 'Barbero',
    });

    expect(plan.estadoTextoCon(barberia), 'Sin citas');
    expect(plan.estadoTexto, 'Sin clases');
  });
}
