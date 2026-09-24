import 'package:flutter_test/flutter_test.dart';
import 'package:turnouno_mobile/features/cuenta/data/cuenta_models.dart';

void main() {
  test('la reseña pendiente trae la clase, con quién y cuándo', () {
    final r = ResenaPendiente.desdeJson({
      'reserva_id': 'r1',
      'actividad': 'Pole Sport',
      'con': 'Beto',
      'fecha': '2026-09-20T15:00:00Z',
    });

    expect(r.reservaId, 'r1');
    expect(r.actividad, 'Pole Sport');
    expect(r.con, 'Beto');
  });

  test('sin solicitud de baja, la privacidad solo trae las promociones', () {
    final p = Privacidad.desdeJson({'recibe_promociones': false, 'baja': null});

    expect(p.recibePromociones, isFalse);
    expect(p.baja, isNull);
  });

  test('el estado de la solicitud de baja se explica en palabras', () {
    SolicitudBaja baja(String estado, [String? respuesta]) =>
        Privacidad.desdeJson({
          'baja': {'estado': estado, 'respuesta': respuesta},
        }).baja!;

    expect(baja('pendiente').estadoTexto, 'Solicitud de baja en revisión');
    expect(baja('atendida').estadoTexto, 'Tus datos se dieron de baja');
    expect(
      baja('rechazada', 'Tienes un adeudo').estadoTexto,
      'Solicitud rechazada · Tienes un adeudo',
    );
  });
}
