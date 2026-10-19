import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// La agenda abre en el día de hoy del NEGOCIO, no del teléfono (ADR 0166): con el
/// desfase de su zona que manda el API al entrar (`zona_desfase_min`), antes de
/// cargar sus sesiones. Sin él (API anterior), el día del teléfono.
void main() {
  Sesion sesion([int? desfase]) => Sesion.desdeJson(
    'demo',
    '1|x',
    {'nombre': 'Ana', 'rol': 'admin', 'roles': ['admin']},
    {'nombre': 'Demo', 'zona_desfase_min': ?desfase},
  );

  test('a las 21:00 del 1 en CDMX ya es el 2 en UTC: hoy es el 1 en el negocio', () {
    final ahora = DateTime.utc(2026, 10, 2, 3);
    expect(sesion(-360).hoyEnNegocio(ahora), DateTime(2026, 10, 1));
    expect(sesion(-360).desfaseNegocioMin, -360);
  });

  test('se guarda y se recupera con la sesión', () {
    final guardada = Sesion.desdeAlmacen(sesion(-300).aJson());
    expect(guardada?.desfaseNegocioMin, -300);
  });

  test('sin el desfase (API anterior), el día del teléfono', () {
    final ahora = DateTime.now();
    expect(
      sesion().hoyEnNegocio(ahora),
      DateTime(ahora.year, ahora.month, ahora.day),
    );
  });
}
