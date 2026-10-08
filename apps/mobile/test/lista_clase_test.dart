import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';

/// La lista de una clase (GET /sesiones/{id}/reservas, ADR 0101): desde cuándo se
/// pasa lista y si ya empezó los dice el servidor; solo se marca a quien tiene su
/// lugar confirmado.
void main() {
  Map<String, dynamic> reserva(String id, String estado) => {
    'id': id,
    'persona': 'Persona $id',
    'estado': estado,
    'asistencia': null,
  };

  test('lee la ventana y si ya empezó desde meta', () {
    final lista = ListaClase.desdeJson({
      'data': [reserva('a', 'confirmada'), reserva('b', 'pendiente_pago')],
      'meta': {'asistencia_desde': '2030-01-09T15:30:00Z', 'empezo': false},
    });

    expect(lista.asistentes, hasLength(2));
    expect(lista.empezo, isFalse);
    expect(lista.abierta(DateTime.utc(2030, 1, 9, 15, 0)), isFalse);
    expect(lista.abierta(DateTime.utc(2030, 1, 9, 15, 31)), isTrue);
  });

  test('solo quien tiene su lugar confirmado se puede marcar', () {
    final lista = ListaClase.desdeJson({
      'data': [
        reserva('a', 'confirmada'),
        reserva('b', 'ofrecida'),
        reserva('c', 'pendiente_pago'),
      ],
    });

    expect(lista.asistentes.map((a) => a.marcable), [true, false, false]);
    // Sin meta (servidor anterior): abierta y sin empezar.
    expect(lista.abierta(DateTime.now()), isTrue);
    expect(lista.empezo, isFalse);
  });
}
