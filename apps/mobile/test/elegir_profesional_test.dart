import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/presentation/elegir_profesional.dart';

/// «Ver horarios de» en la app, igual que en la web: todo el equipo o alguien
/// específico, elegido por su foto (con lupa). Datos sintéticos.
void main() {
  const equipo = [
    OpcionCita(id: 'ana', nombre: 'Ana Pérez', fotoUrl: 'https://x/ana.webp'),
    OpcionCita(id: 'luis', nombre: 'Luis López'),
  ];

  Future<List<String?>> montar(
    WidgetTester tester,
    String? seleccionado,
  ) async {
    final cambios = <String?>[];
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ElegirProfesional(
            profesionales: equipo,
            seleccionado: seleccionado,
            alCambiar: cambios.add,
          ),
        ),
      ),
    );
    return cambios;
  }

  testWidgets(
    'con todo el equipo no pide fotos; al elegir alguien queda el primero',
    (tester) async {
      final cambios = await montar(tester, null);

      expect(find.text('Todo el equipo'), findsOneWidget);
      expect(find.text('Selecciona un profesionista'), findsNothing);

      await tester.tap(find.text('Alguien específico'));
      expect(cambios, ['ana']);
    },
  );

  testWidgets(
    'con alguien, se elige por su tarjeta y la lupa muestra la foto',
    (tester) async {
      final cambios = await montar(tester, 'ana');

      // Primer nombre en su tarjeta; solo quien tiene foto lleva lupa.
      expect(find.text('Ana'), findsOneWidget);
      expect(find.text('Luis'), findsOneWidget);
      expect(find.byIcon(Icons.zoom_in), findsOneWidget);

      await tester.tap(find.text('Luis'));
      expect(cambios, ['luis']);

      await tester.tap(find.byIcon(Icons.zoom_in));
      await tester.pumpAndSettle();
      expect(find.text('Ana Pérez'), findsOneWidget);
      // Ver la foto no elige a nadie.
      expect(cambios, ['luis']);
      await tester.tap(find.text('Cerrar'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Todo el equipo'));
      expect(cambios, ['luis', null]);
    },
  );
}
