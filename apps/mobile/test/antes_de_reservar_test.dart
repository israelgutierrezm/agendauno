import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';

/// Antes de reservar una clase (M05): lo que dice el servidor, en palabras del alumno.
/// El plan y lo que consume, hasta cuándo cancela sin costo, o el motivo si no puede.

Map<String, dynamic> antes(Map<String, dynamic> extra) => {
  'resultado': 'reserva',
  'motivo': null,
  'mensaje': null,
  'unidades': 1000,
  'plan': {
    'nombre': 'Pack 8 clases',
    'ilimitado': false,
    'vence': null,
    'disponible_despues': 7000,
  },
  'de_pago': null,
  'cancelacion': {
    'limite': '2030-01-10T08:00:00Z',
    'horas_limite': 6,
    'penaliza_tarde': true,
    'cancela_tarde': true,
  },
  'que_llevar': 'Ropa cómoda',
  ...extra,
};

void main() {
  test(
    'con plan dice cuánto consume, cuánto le queda y hasta cuándo cancela',
    () {
      final a = AntesDeReservar.desdeJson(antes({}));
      expect(a.puedeReservar, isTrue);
      expect(
        a.consumoTexto,
        'Usa Pack 8 clases: se descuenta 1 crédito y te quedan 7 créditos.',
      );
      expect(
        a.cancelacionTexto(DateTime.utc(2030, 1, 9, 12)),
        startsWith('Cancela sin costo hasta el '),
      );
      expect(
        a.cancelacionTexto(DateTime.utc(2030, 1, 9, 12)),
        endsWith('Después, cancelar descuenta la clase.'),
      );
      expect(
        a.cancelacionTexto(DateTime.utc(2030, 1, 10, 9)),
        'Ya pasó el límite para cancelar sin costo. '
        'Después, cancelar descuenta la clase.',
      );
      expect(a.queLlevar, 'Ropa cómoda');
    },
  );

  test('en lista de espera no promete consumo ni límite de cancelación', () {
    final a = AntesDeReservar.desdeJson(
      antes({'resultado': 'lista_espera', 'unidades': 0}),
    );
    expect(a.esListaEspera, isTrue);
    expect(a.consumoTexto, contains('No se descuenta nada'));
    expect(a.cancelacionTexto(DateTime.utc(2030, 1, 9, 12)), isNull);
  });

  test('sin plan dice el motivo y no ofrece reservar', () {
    final a = AntesDeReservar.desdeJson(
      antes({
        'resultado': 'no_disponible',
        'motivo': 'vigencia',
        'mensaje': 'No tienes un plan vigente.',
        'unidades': 0,
        'plan': null,
      }),
    );
    expect(a.puedeReservar, isFalse);
    expect(a.motivoTexto, 'Tu plan no está vigente ese día.');
    expect(a.consumoTexto, isNull);
  });
}
