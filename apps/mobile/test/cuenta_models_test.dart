import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';

void main() {
  group('DerechoMiembro', () {
    test('parsea un pack y calcula los creditos disponibles', () {
      final d = DerechoMiembro.desdeJson({
        'ilimitado': false,
        'saldo': 8000,
        'disponible': 7000,
      });

      expect(d.ilimitado, isFalse);
      expect(d.disponible, 7000);
      expect(d.creditosDisponibles, 7); // 1 credito = 1000 unidades
    });

    test('parsea una membresia ilimitada', () {
      final d = DerechoMiembro.desdeJson({'ilimitado': true, 'saldo': null, 'disponible': null});

      expect(d.ilimitado, isTrue);
      expect(d.creditosDisponibles, 0);
    });
  });

  test('ReservaMiembro y ClaseMiembro parsean sus campos', () {
    final r = ReservaMiembro.desdeJson({
      'id': 'r1',
      'estado': 'confirmada',
      'oferta': 'Nivel 1',
      'inicia_en': '2026-10-01T14:00:00+00:00',
      'zona_horaria': 'America/Mexico_City',
    });
    expect(r.id, 'r1');
    expect(r.estado, 'confirmada');
    expect(r.oferta, 'Nivel 1');

    final c = ClaseMiembro.desdeJson({
      'id': 's1',
      'oferta': 'Nivel 1',
      'inicia_en': '2026-10-01T14:00:00+00:00',
      'zona_horaria': 'America/Mexico_City',
      'capacidad': 12,
    });
    expect(c.id, 's1');
    expect(c.capacidad, 12);
  });

  test('Sesion se arma desde el usuario del login', () {
    final s = Sesion.desdeJson('estudio-a', '1|abc', {'nombre': 'Ana', 'rol': 'miembro'});

    expect(s.slug, 'estudio-a');
    expect(s.bearer, '1|abc');
    expect(s.nombre, 'Ana');
    expect(s.rol, 'miembro');
    // Sin perfil del estudio: clases con la terminología por defecto.
    expect(s.modalidad, Modalidad.clases);
    expect(s.terminologia.sesion, 'Clase');
  });

  test('Sesion toma la modalidad y la terminología del perfil del estudio', () {
    final s = Sesion.desdeJson('barberia', '1|abc', {'nombre': 'Beto', 'rol': 'recepcionista'}, {
      'perfil_config': {
        'modalidad': 'citas',
        'terminologia': {'sesion': 'Cita', 'miembro': 'Cliente', 'instructor': 'Barbero'},
      },
    });

    expect(s.esCitas, isTrue);
    expect(s.terminologia.miembro, 'Cliente');
    expect(s.terminologia.instructor, 'Barbero');
  });

  group('OpcionesReprogramar', () {
    test('cita: horarios libres del día', () {
      final o = OpcionesReprogramar.desdeJson({
        'puede': true,
        'motivo': null,
        'tipo': 'cita',
        'restantes': 1,
        'slots': [
          {'inicia': '2030-01-08T18:00:00+00:00', 'termina': '2030-01-08T19:00:00+00:00'},
        ],
      });
      expect(o.puede, isTrue);
      expect(o.tipo, 'cita');
      expect(o.horarios, ['2030-01-08T18:00:00+00:00']);
      expect(o.sesiones, isEmpty);
    });

    test('clase: otras fechas; y el motivo cuando ya no se puede', () {
      final o = OpcionesReprogramar.desdeJson({
        'puede': true,
        'tipo': 'clase',
        'restantes': 2,
        'sesiones': [
          {'id': 's9', 'inicia_en': '2030-01-09T14:00:00+00:00', 'zona_horaria': 'America/Mexico_City'},
        ],
      });
      expect(o.sesiones.single.id, 's9');
      expect(o.sesiones.single.iniciaEn, '2030-01-09T14:00:00+00:00');

      final no = OpcionesReprogramar.desdeJson({
        'puede': false,
        'motivo': 'Ya no se puede cambiar: faltan menos de 12 h.',
        'tipo': 'cita',
        'restantes': 1,
      });
      expect(no.puede, isFalse);
      expect(no.motivo, contains('12 h'));
    });
  });

  group('OpcionCita', () {
    test('un paquete trae los servicios que incluye, en orden', () {
      final o = OpcionCita.desdeJson({
        'id': 'o1',
        'nombre': 'Limpieza dental completa',
        'duracion_minutos': 60,
        'incluye': ['Limpieza dental', 'Aplicación de flúor'],
      });

      expect(o.incluye, ['Limpieza dental', 'Aplicación de flúor']);
    });

    test('un servicio simple (o un API anterior) no incluye nada', () {
      final o = OpcionCita.desdeJson({'id': 'o2', 'nombre': 'Corte'});

      expect(o.incluye, isEmpty);
    });
  });
}
