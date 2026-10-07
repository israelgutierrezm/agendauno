import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/formato.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';

void main() {
  test('la tarjeta domiciliada se muestra sin el número completo', () {
    final t = TarjetaDomiciliada.desdeJson({
      'marca': 'visa',
      'ultimos4': '4242',
      'expira': '08/30',
    });

    expect(t.texto, 'Visa terminación 4242');
    expect(t.expira, '08/30');
    expect(const TarjetaDomiciliada().texto, 'Tarjeta terminación ····');
  });

  test('la membresía renovable trae si se cobra sola y el último rechazo', () {
    final m = MembresiaRenovable.desdeJson({
      'id': 'a1',
      'producto': 'Mensualidad',
      'monto_minor': 129900,
      'proxima_cobro_en': '2026-10-24',
      'automatico': true,
      'error': 'La tarjeta venció.',
    });

    expect(m.automatico, isTrue);
    expect(m.error, 'La tarjeta venció.');
    // Sin moneda en la respuesta, la del negocio (pesos por omisión).
    expect(m.moneda, 'MXN');
    expect(Formato.dinero(m.montoMinor!, m.moneda), r'$1,299.00');
    expect(Formato.fechaLarga(m.proximaCobroEn), '24 de octubre');
  });

  test('la membresía renovable trae la moneda de su plan', () {
    final m = MembresiaRenovable.desdeJson({
      'id': 'a2',
      'monto_minor': 15000000,
      'moneda': 'COP',
    }, moneda: 'MXN');
    expect(m.moneda, 'COP');
    expect(Formato.dinero(m.montoMinor!, m.moneda), 'COP 150,000.00');

    // Sin moneda en la respuesta, la del negocio que le pasa el repositorio.
    final sinMoneda = MembresiaRenovable.desdeJson({
      'id': 'a3',
      'monto_minor': 4500,
    }, moneda: 'EUR');
    expect(sinMoneda.moneda, 'EUR');
  });

  test('el dinero se forma desde centavos, con miles', () {
    expect(Formato.dinero(5, 'MXN'), r'$0.05');
    expect(Formato.dinero(123456789, 'MXN'), r'$1,234,567.89');
  });
}
