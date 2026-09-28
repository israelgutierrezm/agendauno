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
    expect(Formato.dinero(m.montoMinor!), r'$1,299.00');
    expect(Formato.fechaLarga(m.proximaCobroEn), '24 de octubre');
  });

  test('el dinero se forma desde centavos, con miles', () {
    expect(Formato.dinero(5), r'$0.05');
    expect(Formato.dinero(123456789), r'$1,234,567.89');
  });
}
