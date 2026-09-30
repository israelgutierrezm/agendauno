import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';

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

  test('los avisos por WhatsApp solo se ofrecen si el negocio los usa', () {
    final sin = Privacidad.desdeJson({'recibe_promociones': true});
    final con = Privacidad.desdeJson({
      'recibe_promociones': true,
      'whatsapp_disponible': true,
      'acepta_whatsapp': true,
    });

    expect(sin.whatsappDisponible, isFalse);
    expect(sin.aceptaWhatsapp, isFalse);
    expect(con.whatsappDisponible, isTrue);
    expect(con.aceptaWhatsapp, isTrue);
  });

  test(
    'al agendar se ofrecen si el negocio los usa, aún no los aceptó y tiene celular',
    () {
      Privacidad p(bool acepta, bool celular) => Privacidad.desdeJson({
        'whatsapp_disponible': true,
        'acepta_whatsapp': acepta,
        'whatsapp_con_celular': celular,
      });

      expect(p(false, true).ofrecerWhatsapp, isTrue);
      expect(p(true, true).ofrecerWhatsapp, isFalse);
      expect(p(false, false).ofrecerWhatsapp, isFalse);
      expect(Privacidad.desdeJson({}).ofrecerWhatsapp, isFalse);
    },
  );

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
