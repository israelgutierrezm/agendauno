import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// Personal sin sucursal (ADR 0098) y moneda del negocio (ADR 0097) en la sesión de
/// la app: vienen de `/yo` y se conservan al guardar la sesión. Datos sintéticos.
void main() {
  test(
    'la sesión sabe si falta sucursal y en qué moneda trabaja el negocio',
    () {
      final s = Sesion.desdeJson(
        'estudio-a',
        '1|x',
        {'nombre': 'Recepción', 'sin_sucursal': true},
        {'moneda': 'USD'},
      );
      expect(s.sinSucursal, isTrue);
      expect(s.moneda, 'USD');

      final guardada = Sesion.desdeAlmacen(s.aJson())!;
      expect(guardada.sinSucursal, isTrue);
      expect(guardada.moneda, 'USD');

      // Al asignarle una, `/yo` lo dice y la sesión se actualiza.
      expect(s.conUsuario({'sin_sucursal': false}).sinSucursal, isFalse);
    },
  );

  test('sin datos, tiene sucursal y la moneda es el peso mexicano', () {
    final s = Sesion.desdeJson('estudio-a', '1|x', {'nombre': 'Ana'});
    expect(s.sinSucursal, isFalse);
    expect(s.moneda, 'MXN');
  });
}
