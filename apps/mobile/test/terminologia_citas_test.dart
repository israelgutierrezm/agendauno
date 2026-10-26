import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/corte_planes.dart';

/// En un negocio de citas (TurnoUno) lo que pasa al no asistir y el corte del bono
/// hablan de su cita y de su bono, con la palabra del negocio: nunca «reserva»,
/// «crédito de su plan» ni «Reservadas».

EfectoInasistencia _efectoCita(Map<String, dynamic> cambios) =>
    EfectoInasistencia.desdeJson({
      'con_credito': true,
      'pierde_credito': true,
      'unidades': 1000,
      ...cambios,
    });

void main() {
  test('no asistir, en citas: su cita y su bono', () {
    expect(
      _efectoCita({}).textoCon(esCitas: true),
      'Pierde 1 cita de su bono.',
    );
    expect(
      _efectoCita({'unidades': 2000}).textoCon(esCitas: true),
      'Pierde 2 citas de su bono.',
    );
    expect(
      _efectoCita({
        'con_credito': false,
        'pierde_credito': false,
      }).textoCon(esCitas: true),
      'No usó su bono en esta cita: su saldo no cambia.',
    );
    expect(
      _efectoCita({
        'tolerada': true,
        'pierde_credito': false,
      }).textoCon(esCitas: true),
      contains('no pierde la cita de su bono'),
    );
    expect(
      _efectoCita({'pierde_credito': false}).textoCon(esCitas: true),
      'Su política no cobra las faltas: la cita vuelve a su bono.',
    );
    // Con la palabra del negocio.
    expect(
      _efectoCita({}).textoCon(
        esCitas: true,
        t: const Terminologia(sesion: 'Sesión', miembro: 'Paciente'),
      ),
      'Pierde 1 sesión de su bono.',
    );
    // En clases, como siempre.
    expect(_efectoCita({}).texto, 'Pierde 1 crédito de su plan.');
    for (final e in [
      _efectoCita({}),
      _efectoCita({'con_credito': false}),
      _efectoCita({'pierde_credito': false}),
    ]) {
      final texto = e.textoCon(esCitas: true);
      expect(texto, isNot(contains('reserva')));
      expect(texto, isNot(contains('crédito')));
    }
  });

  test('el corte del bono: «Agendadas», no «Reservadas»', () {
    final plan = PlanCorte.desdeJson({
      'id': 'a1',
      'derecho_id': 'd1',
      'estado': 'vigente',
      'ilimitado': false,
      'unidades': {
        'incluidas': 5000,
        'usadas': 1000,
        'apartadas': 2000,
        'disponibles': 2000,
      },
    });

    final citas = plan.numerosCon(esCitas: true).map((n) => n.$1).toList();
    expect(citas, contains('Agendadas'));
    expect(citas, isNot(contains('Reservadas')));
    expect(plan.numeros.map((n) => n.$1), contains('Reservadas'));
  });
}
