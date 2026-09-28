import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';

void main() {
  test('una clase sin lugares ofrece la lista de espera', () {
    final llena = ClaseMiembro.desdeJson({
      'id': 's1',
      'capacidad': 10,
      'ocupados': 10,
    });
    final libre = ClaseMiembro.desdeJson({
      'id': 's2',
      'capacidad': 10,
      'ocupados': 3,
    });
    final sinCupo = ClaseMiembro.desdeJson({
      'id': 's3',
      'capacidad': null,
      'ocupados': 40,
    });

    expect(llena.llena, isTrue);
    expect(libre.llena, isFalse);
    expect(sinCupo.llena, isFalse);
  });

  test(
    'la reserva muestra su estado en español y sabe si le ofrecieron lugar',
    () {
      final ofrecida = ReservaMiembro.desdeJson({
        'id': 'r1',
        'sesion_id': 's1',
        'estado': 'ofrecida',
      });
      final apartada = ReservaMiembro.desdeJson({
        'id': 'r2',
        'sesion_id': 's2',
        'estado': 'pendiente_pago',
        'orden_id': 'o1',
      });

      expect(ofrecida.ofrecida, isTrue);
      expect(ofrecida.estadoTexto, 'Lugar disponible');
      expect(apartada.estadoTexto, 'Pendiente de pago');
      expect(apartada.ordenId, 'o1');

      final cuenta = MiCuenta(
        derechos: const [],
        reservas: [ofrecida, apartada],
        clases: const [],
      );
      expect(cuenta.sesionesReservadas, {'s1', 's2'});
    },
  );

  test('los consentimientos pendientes se leen con su texto', () {
    final c = ConsentimientoPendiente.desdeJson({
      'id': 'w1',
      'titulo': 'Carta responsiva',
      'contenido': 'Acepto las condiciones.',
      'version': 2,
    });

    expect(c.titulo, 'Carta responsiva');
    expect(c.version, 2);
  });
}
