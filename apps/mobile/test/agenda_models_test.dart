import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';

/// Una cita como la manda GET /sesiones (ADR 0104): en qué va su atención y su
/// pago lo calcula el servidor.
Map<String, dynamic> _cita({
  String estado = 'confirmada',
  String? asistencia,
  String? orden,
  String atencion = 'confirmada',
  String? pago,
}) => {
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
  'clase': null,
  'ocupacion': null,
  'cita': {
    'reserva_id': 'r1',
    'cliente': 'Ana López',
    'estado': estado,
    'asistencia': asistencia,
    'orden_id': orden,
    'por_cobrar': pago == 'por_cobrar',
    'estado_atencion': atencion,
    'estado_pago': pago,
  },
};

/// Una clase con su bloque `clase` y la ocupación que se muestra.
Map<String, dynamic> _clase({int ocupados = 3, int marcadas = 0}) => {
  ..._cita(),
  'tipo': 'clase',
  'oferta': 'Pole Nivel 1',
  'capacidad': 12,
  'ocupados': ocupados,
  'en_espera': 1,
  'marcadas': marcadas,
  'clase': {
    'capacidad': 12,
    'ocupados': ocupados,
    'libres': 12 - ocupados,
    'en_espera': 1,
    'lugares': 0,
    'de_pago': false,
    'precio_minor': null,
  },
  'ocupacion': {
    'ocupados': ocupados,
    'capacidad': 12,
    'porcentaje': (ocupados * 100 / 12).round(),
  },
  'cita': null,
};

void main() {
  test('parsea una cita con su titular y duración', () {
    final s = SesionAgenda.desdeJson(_cita());

    expect(s.tipo, TipoSesion.cita);
    expect(s.esCita, isTrue);
    expect(s.cita!.cliente, 'Ana López');
    expect(s.duracionMin, 30);
    expect(s.precioMinor, 25000);
    expect(s.clase, isNull);
    expect(s.ocupacion, isNull);
  });

  test('el estado de la cita es el que calcula el servidor, no la hora', () {
    // Ya pasó su hora, pero el servidor dice «en servicio»: eso se muestra.
    expect(
      SesionAgenda.desdeJson(_cita(atencion: 'en_servicio')).estadoCita,
      EstadoCita.enServicio,
    );
    expect(
      SesionAgenda.desdeJson(
        _cita(asistencia: 'ausente', atencion: 'no_asistio'),
      ).estadoCita,
      EstadoCita.noAsistio,
    );
    expect(
      SesionAgenda.desdeJson(_cita(atencion: 'sin_registrar')).estadoCita,
      EstadoCita.sinRegistrar,
    );
    // Un estado que esta versión no conoce no se adivina: no se muestra.
    expect(
      SesionAgenda.desdeJson(_cita(atencion: 'en_sala')).estadoCita,
      isNull,
    );
  });

  test('una cita sin titular se lee por el estado de la sesión', () {
    final sinCliente = SesionAgenda.desdeJson({..._cita(), 'cita': null});
    final cancelada = SesionAgenda.desdeJson({
      ..._cita(),
      'cita': null,
      'estado': 'cancelada',
    });

    expect(sinCliente.cita, isNull);
    expect(sinCliente.estadoCita, isNull);
    expect(cancelada.estadoCita, EstadoCita.cancelada);
  });

  test('sabe si una cita falta de cobrar por su estado de pago', () {
    expect(
      SesionAgenda.desdeJson(_cita(orden: 'o9', pago: 'por_cobrar')).porCobrar,
      isTrue,
    );
    expect(
      SesionAgenda.desdeJson(
        _cita(estado: 'pendiente_pago', orden: 'o9', pago: 'por_pagar'),
      ).porCobrar,
      isTrue,
    );
    expect(
      SesionAgenda.desdeJson(_cita(orden: 'o9', pago: 'pagada')).porCobrar,
      isFalse,
    );
    // Con su plan: sin orden ni cobro.
    expect(SesionAgenda.desdeJson(_cita()).porCobrar, isFalse);
    expect(
      SesionAgenda.desdeJson(
        _cita(orden: 'o9', pago: 'por_pagar'),
      ).cita!.estadoPago,
      EstadoPagoCita.porPagar,
    );
  });

  test('sabe si ya empezó y falta pasar lista o marcar la llegada', () {
    final antes = DateTime.utc(2026, 10, 5, 15, 50);
    final durante = DateTime.utc(2026, 10, 5, 16, 10);

    expect(
      SesionAgenda.desdeJson(_clase(marcadas: 1)).faltaMarcar(antes),
      isFalse,
    );
    expect(
      SesionAgenda.desdeJson(_clase(marcadas: 1)).faltaMarcar(durante),
      isTrue,
    );
    expect(
      SesionAgenda.desdeJson(_clase(marcadas: 3)).faltaMarcar(durante),
      isFalse,
    );
    expect(SesionAgenda.desdeJson(_cita()).faltaMarcar(durante), isTrue);
    expect(
      SesionAgenda.desdeJson(
        _cita(asistencia: 'presente'),
      ).faltaMarcar(durante),
      isFalse,
    );
  });

  test('una clase trae su cupo y la ocupación que decide el servidor', () {
    final clase = SesionAgenda.desdeJson(_clase(ocupados: 9));

    expect(clase.esCita, isFalse);
    expect(clase.cita, isNull);
    expect(clase.clase!.libres, 3);
    expect(clase.clase!.enEspera, 1);
    expect(clase.ocupacion!.porcentaje, 75);
    expect(clase.ocupacion!.fraccion, closeTo(0.75, 0.001));
    expect(
      const Profesional(id: 'p1', nombre: 'Beto Ramírez Soto').iniciales,
      'BR',
    );
  });

  test('un API anterior sin los bloques nuevos: el cupo sale de los conteos y '
      'la ocupación no se calcula', () {
    final clase = SesionAgenda.desdeJson(
      {..._clase(ocupados: 12)}
        ..removeWhere((k, _) => k == 'clase' || k == 'ocupacion'),
    );

    expect(clase.clase!.libres, 0);
    expect(clase.clase!.llena, isTrue);
    expect(clase.ocupacion, isNull);
  });

  test('un tipo desconocido (o sin tipo) es un error, no una clase', () {
    expect(
      () => SesionAgenda.desdeJson({..._clase(), 'tipo': 'taller'}),
      throwsA(
        isA<TipoSesionDesconocido>().having(
          (e) => e.toString(),
          'mensaje',
          contains('taller'),
        ),
      ),
    );
    expect(
      () => SesionAgenda.desdeJson({..._clase()}..remove('tipo')),
      throwsA(isA<TipoSesionDesconocido>()),
    );
  });
}
