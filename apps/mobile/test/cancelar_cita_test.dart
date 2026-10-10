import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/presentation/cita_sheet.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// Cancelar una cita desde su hoja: se confirma antes y, como la cita cancelada ya
/// no vuelve en la agenda, la hoja se cierra (antes se quedaba girando). Datos
/// sintéticos.

final _hoy = DateTime.now();
String _a(int h) =>
    DateTime(_hoy.year, _hoy.month, _hoy.day, h).toUtc().toIso8601String();

final _cita = SesionAgenda.desdeJson({
  'id': 'cita-1',
  'tipo': 'cita',
  'oferta': 'Corte',
  'oferta_id': 'o1',
  'instructor': 'Beto Ramírez',
  'instructor_id': 'p1',
  'inicia_en': _a(10),
  'termina_en': _a(11),
  'capacidad': 1,
  'ocupados': 1,
  'en_espera': 0,
  'estado': 'programada',
  'clase': null,
  'ocupacion': null,
  'cita': {
    'reserva_id': 'r1',
    'cliente': 'Ana López',
    'estado': 'confirmada',
    'asistencia': null,
    'orden_id': null,
    'por_cobrar': false,
    'estado_atencion': 'confirmada',
    'estado_pago': null,
  },
});

/// La agenda del día; cancelar la quita (como el API) y recarga.
final _canceladas = <String>[];

class _Agenda extends AgendaController {
  @override
  Future<AgendaDia> build() async => AgendaDia(
    sesiones: _canceladas.contains('r1') ? const [] : [_cita],
    profesionales: const [Profesional(id: 'p1', nombre: 'Beto Ramírez')],
  );

  @override
  Future<void> cancelar(SesionAgenda s) async {
    _canceladas.add(s.cita!.reservaId);
    ref.invalidateSelf();
    await future;
  }
}

void main() {
  testWidgets('se confirma antes de cancelar y al cancelar la hoja se cierra', (
    tester,
  ) async {
    _canceladas.clear();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(
            const Sesion(
              slug: 'demo',
              bearer: 't',
              nombre: 'Recepción',
              rol: 'propietario',
              permisos: ['*'],
              modalidad: Modalidad.citas,
            ),
          ),
          agendaProvider.overrideWith(_Agenda.new),
        ],
        child: MaterialApp(
          home: Scaffold(
            body: Consumer(
              builder: (context, ref, _) {
                // La agenda ya cargada, como cuando se toca la cita.
                ref.watch(agendaProvider);
                return TextButton(
                  onPressed: () => mostrarHojaCita(context, _cita),
                  child: const Text('Abrir'),
                );
              },
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Abrir'));
    await tester.pumpAndSettle();

    // Volver no cancela.
    await tester.tap(find.text('Cancelar cita'));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('¿Cancelar la cita de Ana López'),
      findsOneWidget,
    );
    await tester.tap(find.text('Volver'));
    await tester.pumpAndSettle();
    expect(_canceladas, isEmpty);
    expect(find.text('Cancelar cita'), findsOneWidget);

    await tester.tap(find.text('Cancelar cita'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sí, cancelar'));
    await tester.pumpAndSettle();

    expect(_canceladas, ['r1']);
    expect(find.text('Cancelar cita'), findsNothing);
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(
      find.text('Cita cancelada; el horario quedó libre.'),
      findsOneWidget,
    );
  });
}
