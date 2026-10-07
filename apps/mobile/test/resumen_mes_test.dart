import 'package:agendauno/features/inicio/data/resumen_mes.dart';
import 'package:agendauno/features/inicio/presentation/resumen_mes_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Datos sintéticos, como los devuelven /reportes/negocio y /reportes/equipo.
const _negocio = {
  'ingresos_minor': 10425000,
  'ocupacion_pct': 96,
  'ocupacion_agenda_pct': 57,
  'no_show_pct': 10,
  'alumnos_activos': 16,
};
const _equipo = {
  'profesionales': [
    {
      'nombre': 'Sofía Profesional',
      'ocupacion_pct': 60,
      'agendado_en_horario_min': 7980,
      'disponible_min': 13200,
    },
    {
      'nombre': 'Beto Instructor',
      'ocupacion_pct': null,
      'agendado_en_horario_min': 0,
      'disponible_min': 0,
    },
  ],
};

void main() {
  test('en citas la ocupación es la de la agenda; en clases, la del cupo', () {
    expect(
      ResumenMes.desdeJson(_negocio, _equipo, esCitas: true).ocupacionPct,
      57,
    );
    expect(
      ResumenMes.desdeJson(_negocio, _equipo, esCitas: false).ocupacionPct,
      96,
    );
  });

  testWidgets('muestra los números del mes y la agenda de cada quien', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: ResumenMesCard(
              mes: ResumenMes.desdeJson(_negocio, _equipo, esCitas: true),
              clientes: 'clientes',
              hoy: DateTime(2026, 9, 29),
            ),
          ),
        ),
      ),
    );

    expect(find.text('Del 1 al 29 de septiembre'), findsOneWidget);
    expect(find.text(r'$104,250.00'), findsOneWidget);
    expect(find.text('57%'), findsOneWidget);
    expect(find.text('10%'), findsOneWidget);
    expect(find.text('Clientes activos'), findsOneWidget);
    expect(find.text('60% · 133 h de 220 h'), findsOneWidget);
    // Quien no tiene horario de atención no tiene ocupación.
    expect(find.text('Sin horario'), findsOneWidget);
  });

  test(
    'los ingresos van en la moneda del reporte o, sin ella, la del negocio',
    () {
      expect(
        ResumenMes.desdeJson(_negocio, _equipo, esCitas: true).moneda,
        'MXN',
      );
      expect(
        ResumenMes.desdeJson(
          {..._negocio, 'moneda': 'COP'},
          _equipo,
          esCitas: true,
          moneda: 'MXN',
        ).moneda,
        'COP',
      );
      expect(
        ResumenMes.desdeJson(
          _negocio,
          _equipo,
          esCitas: true,
          moneda: 'EUR',
        ).moneda,
        'EUR',
      );
    },
  );

  testWidgets('en otra moneda los ingresos llevan su código', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: ResumenMesCard(
              mes: ResumenMes.desdeJson(
                {..._negocio, 'moneda': 'COP'},
                _equipo,
                esCitas: true,
              ),
              clientes: 'clientes',
              hoy: DateTime(2026, 9, 29),
            ),
          ),
        ),
      ),
    );

    expect(find.text('COP 104,250.00'), findsOneWidget);
    expect(find.text(r'$104,250.00'), findsNothing);
  });
}
