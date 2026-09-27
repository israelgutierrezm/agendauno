import 'package:flutter_test/flutter_test.dart';
import 'package:turnouno_mobile/core/calendario/calendario.dart';
import 'package:turnouno_mobile/features/agenda/data/agenda_models.dart';
import 'package:turnouno_mobile/features/auth/data/sesion.dart';
import 'package:turnouno_mobile/features/cuenta/data/cuenta_models.dart';
import 'package:turnouno_mobile/features/cuenta/data/eventos_cuenta.dart';
import 'package:turnouno_mobile/features/instructor/application/mis_clases_controller.dart';
import 'package:turnouno_mobile/features/instructor/presentation/instructor_screen.dart';

void main() {
  group('portal del alumno', () {
    final cuenta = MiCuenta(
      derechos: const [],
      reservas: [
        ReservaMiembro.desdeJson({
          'id': 'r1',
          'sesion_id': 's1',
          'estado': 'confirmada',
          'oferta': 'Pole Nivel 1',
          'sucursal': 'Roma Norte',
          'instructor': 'Mariana',
          'inicia_en': '2030-01-10T15:00:00+00:00',
          'termina_en': '2030-01-10T16:00:00+00:00',
        }),
        ReservaMiembro.desdeJson({
          'id': 'r2',
          'sesion_id': 's9',
          'estado': 'en_espera',
          'oferta': 'Exotic',
          'inicia_en': '2030-01-09T15:00:00+00:00',
        }),
        // Cancelada: ya no va a su calendario.
        ReservaMiembro.desdeJson({
          'id': 'r3',
          'estado': 'cancelada',
          'inicia_en': '2030-01-08T15:00:00+00:00',
        }),
      ],
      clases: [
        ClaseMiembro.desdeJson({
          'id': 's1',
          'oferta': 'Pole Nivel 1',
          'inicia_en': '2030-01-10T15:00:00+00:00',
          'capacidad': 10,
          'ocupados': 3,
        }),
        ClaseMiembro.desdeJson({
          'id': 's2',
          'oferta': 'Flexibilidad',
          'inicia_en': '2030-01-11T15:00:00+00:00',
          'capacidad': 8,
          'ocupados': 8,
        }),
      ],
    );

    test('la reserva trae su fin y con quién', () {
      final r = cuenta.reservas.first;
      expect(r.terminaEn, '2030-01-10T16:00:00+00:00');
      expect(r.instructor, 'Mariana');
    });

    test('sus reservas próximas, en orden y sin las canceladas', () {
      expect(proximasReservas(cuenta).map((r) => r.id), ['r2', 'r1']);
    });

    test('el calendario resalta lo suyo y no repite lo que ya reservó', () {
      final eventos = eventosDeCuenta(cuenta);
      expect(eventos.map((e) => e.id), ['r2', 'r1', 's2']);

      final mia = eventos.firstWhere((e) => e.id == 'r1');
      expect(mia.destacado, isTrue);
      expect(mia.estado, 'Reservada');
      expect(mia.tono, TonoEvento.primario);
      expect(mia.detalle, 'Roma Norte · con Mariana');
      expect(mia.fin, isNotNull);

      final espera = eventos.firstWhere((e) => e.id == 'r2');
      expect(espera.tono, TonoEvento.aviso);

      final llena = eventos.firstWhere((e) => e.id == 's2');
      expect(llena.destacado, isFalse);
      expect(llena.estado, 'Llena');
      expect(llena.tono, TonoEvento.aviso);
    });
  });

  group('portal del instructor', () {
    Sesion sesion(String rol, [List<String> roles = const []]) => Sesion(
      slug: 'demo',
      bearer: 't',
      nombre: 'Ana',
      rol: rol,
      roles: roles,
    );

    test('solo quien únicamente imparte usa el portal de instructor', () {
      expect(sesion('instructor').esInstructorAcotado, isTrue);
      expect(
        sesion('instructor', ['instructor', 'miembro']).esInstructorAcotado,
        isTrue,
      );
      expect(
        sesion('admin', ['admin', 'instructor']).esInstructorAcotado,
        isFalse,
      );
      expect(sesion('recepcionista').esInstructorAcotado, isFalse);
    });

    SesionAgenda s(Map<String, dynamic> extra) => SesionAgenda.desdeJson({
      'id': 's1',
      'tipo': 'clase',
      'oferta': 'Pole Nivel 1',
      'sala': 'Sala A',
      'sucursal': 'Roma Norte',
      'inicia_en': '2030-01-10T15:00:00Z',
      'termina_en': '2030-01-10T16:00:00Z',
      'capacidad': 10,
      'ocupados': 6,
      'en_espera': 0,
      ...extra,
    });

    test('sus clases en el calendario: cupo, sede y lista de espera', () {
      final e = eventosDeClases([
        s({'en_espera': 2}),
      ]).single;
      expect(e.destacado, isTrue);
      expect(e.estado, '6/10');
      expect(e.detalle, 'Roma Norte · Sala A');
      expect(e.tono, TonoEvento.aviso);
      expect(
        detalleSesion(s({'en_espera': 2})),
        '6 de 10 lugares ocupados · 2 en lista de espera',
      );
    });

    test('una cita muestra con quién', () {
      final cita = s({
        'tipo': 'cita',
        'capacidad': 1,
        'ocupados': 1,
        'cita': {'reserva_id': 'r1', 'cliente': 'Luis', 'estado': 'confirmada'},
      });
      expect(cupoDe(cita), 'Luis');
      expect(detalleSesion(cita), 'Con Luis');
    });
  });
}
