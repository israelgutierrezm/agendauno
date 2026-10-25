import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/config/producto_app.dart';
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
    expect(Terminologia.clases.instructores, 'Instructores');
    expect(Terminologia.citas.instructores, 'Profesionales');
  });

  test('sin plurales del servidor, las agudas pierden el acento', () {
    expect(const Terminologia(sesion: 'Lección').sesiones, 'Lecciones');
    expect(const Terminologia(sesion: 'Sesión').sesiones, 'Sesiones');
    expect(const Terminologia(instructor: 'Coach').instructores, 'Coaches');
  });

  // ADR 0111: TurnoUno es de citas; nada dice «clase» si el servidor no lo dice.
  test('sin la del servidor, la de esta app: citas en TurnoUno', () {
    const t = Terminologia();
    if (ProductoApp.esTurnoUno) {
      expect([t.sesion, t.miembro, t.instructor], [
        'Cita',
        'Cliente',
        'Profesional',
      ]);
    } else {
      expect([t.sesion, t.miembro, t.instructor], [
        'Clase',
        'Miembro',
        'Instructor',
      ]);
    }
  });

  test('la sesión sin terminología toma la de su modalidad', () {
    final citas = Sesion.desdeJson('b', 't', {'rol': 'miembro'}, {
      'modalidad': 'citas',
    });
    final clases = Sesion.desdeJson('c', 't', {'rol': 'miembro'}, {
      'modalidad': 'clases',
    });

    expect(citas.terminologia.sesion, 'Cita');
    expect(citas.terminologia.miembro, 'Cliente');
    expect(citas.terminologia.instructor, 'Profesional');
    expect(clases.terminologia.sesion, 'Clase');
    // Lo que el servidor no manda sale de la modalidad, no de la otra app.
    final parcial = Sesion.desdeJson('b', 't', {'rol': 'miembro'}, {
      'modalidad': 'citas',
      'perfil_config': {
        'terminologia': {'instructor': 'Barbero'},
      },
    });
    expect(parcial.terminologia.sesion, 'Cita');
    expect(parcial.terminologia.instructor, 'Barbero');
    // Guardada y restaurada, igual; y cambiar de rol la conserva.
    final restaurada = Sesion.desdeAlmacen(citas.aJson())!;
    expect(restaurada.terminologia.sesion, 'Cita');
    expect(restaurada.conUsuario(const {}).terminologia.miembro, 'Cliente');
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
    expect(plan.estadoTextoCon(Terminologia.clases), 'Sin clases');
    expect(plan.estadoTexto, ProductoApp.esTurnoUno ? 'Sin citas' : 'Sin clases');
  });
}
