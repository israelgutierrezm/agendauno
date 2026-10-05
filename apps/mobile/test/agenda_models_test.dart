import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';

Map<String, dynamic> _cita({String estado = 'confirmada', String? asistencia, bool porCobrar = false, String? orden}) =>
    {
      'id': 's1',
      'tipo': 'cita',
      'oferta': 'Corte',
      'oferta_id': 'o1',
      'oferta_precio_clase': 25000,
      'instructor': 'Beto Ramírez',
      'instructor_id': 'p1',
      'sala': null,
      'inicia_en': '2026-10-05T16:00:00Z',
      'termina_en': '2026-10-05T16:30:00Z',
      'capacidad': 1,
      'ocupados': 1,
      'en_espera': 0,
      'estado': 'programada',
      'cita': {
        'reserva_id': 'r1',
        'cliente': 'Ana López',
        'estado': estado,
        'asistencia': asistencia,
        'orden_id': orden,
        'por_cobrar': porCobrar,
      },
    };

void main() {
  test('parsea una cita con su titular y duración', () {
    final s = SesionAgenda.desdeJson(_cita());

    expect(s.esCita, isTrue);
    expect(s.cita!.cliente, 'Ana López');
    expect(s.duracionMin, 30);
    expect(s.precioMinor, 25000);
  });

  test('deriva el estado de la cita según asistencia y hora', () {
    final antes = DateTime.utc(2026, 10, 5, 15, 50);
    final durante = DateTime.utc(2026, 10, 5, 16, 10);
    final despues = DateTime.utc(2026, 10, 5, 17);

    expect(SesionAgenda.desdeJson(_cita()).estadoCita(antes), EstadoCita.confirmada);
    expect(SesionAgenda.desdeJson(_cita(estado: 'pendiente_pago')).estadoCita(antes), EstadoCita.pendientePago);
    expect(SesionAgenda.desdeJson(_cita(asistencia: 'presente')).estadoCita(antes), EstadoCita.llego);
    expect(SesionAgenda.desdeJson(_cita(asistencia: 'presente')).estadoCita(durante), EstadoCita.enServicio);
    expect(SesionAgenda.desdeJson(_cita(asistencia: 'presente')).estadoCita(despues), EstadoCita.completada);
    expect(SesionAgenda.desdeJson(_cita(asistencia: 'ausente')).estadoCita(despues), EstadoCita.noAsistio);
  });

  test('sabe si ya empezó y falta pasar lista o marcar la llegada', () {
    final antes = DateTime.utc(2026, 10, 5, 15, 50);
    final durante = DateTime.utc(2026, 10, 5, 16, 10);
    Map<String, dynamic> clase(int marcadas) =>
        {..._cita(), 'tipo': 'clase', 'cita': null, 'capacidad': 12, 'ocupados': 3, 'marcadas': marcadas};

    expect(SesionAgenda.desdeJson(clase(1)).faltaMarcar(antes), isFalse);
    expect(SesionAgenda.desdeJson(clase(1)).faltaMarcar(durante), isTrue);
    expect(SesionAgenda.desdeJson(clase(3)).faltaMarcar(durante), isFalse);
    expect(SesionAgenda.desdeJson(_cita()).faltaMarcar(durante), isTrue);
    expect(SesionAgenda.desdeJson(_cita(asistencia: 'presente')).faltaMarcar(durante), isFalse);
  });

  test('sabe si una cita falta de cobrar', () {
    expect(SesionAgenda.desdeJson(_cita(orden: 'o9', porCobrar: true)).porCobrar, isTrue);
    expect(SesionAgenda.desdeJson(_cita(orden: 'o9')).porCobrar, isFalse);
    expect(SesionAgenda.desdeJson(_cita(estado: 'pendiente_pago', orden: 'o9')).porCobrar, isTrue);
  });

  test('una clase expone su ocupación y el profesional sus iniciales', () {
    final clase = SesionAgenda.desdeJson({..._cita(), 'tipo': 'clase', 'cita': null, 'capacidad': 12, 'ocupados': 9});

    expect(clase.esCita, isFalse);
    expect(clase.ocupacion, closeTo(0.75, 0.001));
    expect(const Profesional(id: 'p1', nombre: 'Beto Ramírez Soto').iniciales, 'BR');
  });
}
