import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';

/// «No vino» dice lo que de verdad pasa, con las mismas palabras que la web
/// (`confirmaciones.asistencia.efecto`): el crédito que pierde o no, o lo ya
/// pagado que no se devuelve. Datos sintéticos.

EfectoInasistencia _efecto(Map<String, dynamic> cambios) =>
    EfectoInasistencia.desdeJson({
      'con_credito': true,
      'penaliza': true,
      'tolerada': false,
      'pierde_credito': true,
      'unidades': 1000,
      ...cambios,
    });

void main() {
  test('dice cuántos créditos pierde', () {
    expect(_efecto({}).texto, 'Pierde 1 crédito de su plan.');
    expect(_efecto({'unidades': 2000}).texto, 'Pierde 2 créditos de su plan.');
    expect(
      _efecto({'unidades': 1500}).texto,
      'Pierde 1.5 créditos de su plan.',
    );
  });

  test('si se tolera o su política no cobra, no lo pierde', () {
    expect(
      _efecto({'tolerada': true, 'pierde_credito': false}).texto,
      contains('tolerancia'),
    );
    expect(
      _efecto({'penaliza': false, 'pierde_credito': false}).texto,
      contains('vuelve a su saldo'),
    );
  });

  test('sin crédito: el anticipo o el pago no se devuelven', () {
    const sinCredito = {'con_credito': false, 'pierde_credito': false};
    expect(
      _efecto({
        ...sinCredito,
        'conserva': 'anticipo',
        'conserva_minor': 10000,
        'moneda': 'MXN',
      }).texto,
      'El anticipo de \$100.00 no se devuelve: el negocio lo conserva.',
    );
    expect(
      _efecto({
        ...sinCredito,
        'conserva': 'pago',
        'conserva_minor': 25000,
        'moneda': 'MXN',
      }).texto,
      'Ya pagó \$250.00: no se devuelve por no asistir.',
    );
    expect(_efecto(sinCredito).texto, contains('su saldo no cambia'));
  });
}
