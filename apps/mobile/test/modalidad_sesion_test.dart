import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// La modalidad del negocio es la que guarda el servidor (ADR 0104): llega con la
/// sesión junto con sus capacidades, y es la única fuente en la app. Datos
/// sintéticos.
void main() {
  test('la modalidad guardada del negocio manda sobre la de su perfil', () {
    final s = Sesion.desdeJson(
      'barberia',
      't',
      {'nombre': 'Beto', 'rol': 'recepcionista'},
      {
        'modalidad': 'citas',
        'capacidades': {'clases': false, 'citas': true},
        'perfil_config': {'modalidad': 'clases'},
      },
    );

    expect(s.modalidad, Modalidad.citas);
    expect(s.esCitas, isTrue);
    expect(s.capacidades.citas, isTrue);
    expect(s.capacidades.clases, isFalse);
  });

  test('un API anterior: la del perfil y las capacidades de esa modalidad', () {
    final s = Sesion.desdeJson(
      'barberia',
      't',
      {'nombre': 'Beto', 'rol': 'recepcionista'},
      {
        'perfil_config': {'modalidad': 'citas'},
      },
    );

    expect(s.modalidad, Modalidad.citas);
    expect(s.capacidades.citas, isTrue);
    expect(s.capacidades.clases, isFalse);
  });

  test('se guarda y se restaura con sus capacidades', () {
    final s = Sesion.desdeJson(
      'pole',
      't',
      {'nombre': 'Vale', 'rol': 'miembro'},
      {
        'modalidad': 'clases',
        'capacidades': {'clases': true, 'citas': false},
      },
    );
    final restaurada = Sesion.desdeAlmacen(s.aJson())!;

    expect(restaurada.modalidad, Modalidad.clases);
    expect(restaurada.capacidades.clases, isTrue);
    expect(restaurada.capacidades.citas, isFalse);
    // Cambiar de rol (o editar el perfil) conserva lo del negocio.
    final otra = restaurada.conUsuario({'rol': 'instructor'});
    expect(otra.capacidades.clases, isTrue);
    expect(otra.modalidad, Modalidad.clases);
  });
}
