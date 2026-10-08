import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// El plan del negocio (ADR 0107): /yo manda su nivel y las funciones que no
/// incluye; la app no las ofrece (el servidor ya las niega). Se guarda con la sesión.
void main() {
  Sesion conPlan(Map<String, dynamic>? plan) => Sesion.desdeJson(
    'barberia',
    '1|abc',
    {'nombre': 'Ana', 'rol': 'miembro'},
    {'nombre': 'Barbería La Navaja', 'modalidad': 'citas', 'plan': ?plan},
  );

  test(
    'un negocio Premium no ofrece la venta en línea ni el cobro automático',
    () {
      final sesion = conPlan({
        'nivel': 'premium',
        'sin': ['venta_en_linea', 'cobro_automatico', 'facturacion'],
      });

      expect(sesion.nivelPlan, 'premium');
      expect(sesion.tieneFuncion('venta_en_linea'), isFalse);
      expect(sesion.tieneFuncion('cobro_automatico'), isFalse);
      expect(sesion.tieneFuncion('inventario'), isTrue);
    },
  );

  test('el plan se conserva al guardar la sesión y al cambiar de rol', () {
    final sesion = conPlan({
      'nivel': 'individual',
      'sin': ['equipo', 'venta_en_linea'],
    });

    final restaurada = Sesion.desdeAlmacen(sesion.aJson())!;
    expect(restaurada.nivelPlan, 'individual');
    expect(restaurada.funcionesSin, ['equipo', 'venta_en_linea']);

    final otroRol = restaurada.conUsuario({'rol': 'propietario'});
    expect(otroRol.tieneFuncion('equipo'), isFalse);
  });

  test('sin plan (clases o un API anterior) tiene todas las funciones', () {
    final sesion = conPlan(null);

    expect(sesion.nivelPlan, isNull);
    expect(sesion.tieneFuncion('venta_en_linea'), isTrue);
    expect(sesion.tieneFuncion('cobro_automatico'), isTrue);
  });
}
