import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/calendario/calendario.dart';
import 'package:agendauno/core/calendario/calendario_vistas.dart';

void main() {
  group('Calendario', () {
    test('las semanas del mes van de lunes a domingo y lo cubren completo', () {
      // Septiembre de 2026 empieza en martes y termina en miércoles.
      final semanas = Calendario.semanasDelMes(DateTime(2026, 9, 15));
      expect(semanas, hasLength(5));
      expect(semanas.first.first, DateTime(2026, 8, 31));
      expect(semanas.last.last, DateTime(2026, 10, 4));
      expect(semanas.every((s) => s.first.weekday == DateTime.monday), isTrue);
    });

    test('rango visible de cada vista', () {
      final hoy = DateTime(2026, 9, 27); // domingo
      final dia = Calendario.rango(VistaCal.dia, hoy, hoy);
      expect((dia.desde, dia.hasta), (hoy, hoy));
      final semana = Calendario.rango(VistaCal.semana, hoy, hoy);
      expect(
        (semana.desde, semana.hasta),
        (DateTime(2026, 9, 21), DateTime(2026, 9, 27)),
      );
      final lista = Calendario.rango(VistaCal.lista, hoy, hoy);
      expect(lista.hasta, DateTime(2026, 10, 27));
      final mes = Calendario.rango(VistaCal.mes, hoy, hoy);
      expect(mes.desde, DateTime(2026, 8, 31));
    });

    test('moverse por periodos y la etiqueta de cada uno', () {
      final f = DateTime(2026, 1, 31);
      expect(Calendario.mover(VistaCal.mes, f, 1), DateTime(2026, 2));
      expect(Calendario.mover(VistaCal.semana, f, -1), DateTime(2026, 1, 24));
      expect(
        Calendario.etiqueta(VistaCal.dia, DateTime(2026, 9, 23)),
        'Miércoles 23 de septiembre',
      );
      expect(
        Calendario.etiqueta(VistaCal.semana, DateTime(2026, 9, 23)),
        '21 sep – 27 sep',
      );
      expect(
        Calendario.etiqueta(VistaCal.mes, DateTime(2026, 9, 23)),
        'Septiembre de 2026',
      );
    });

    test('Google Calendar recibe las horas en UTC; sin fin dura una hora', () {
      final inicio = DateTime.utc(2030, 1, 8, 15);
      final url = Uri.parse(
        Calendario.enlaceGoogle(
          titulo: 'Pole Nivel 1',
          inicio: inicio,
          lugar: 'Roma Norte',
        ),
      );
      expect(url.host, 'calendar.google.com');
      expect(url.queryParameters['action'], 'TEMPLATE');
      expect(url.queryParameters['text'], 'Pole Nivel 1');
      expect(url.queryParameters['dates'], '20300108T150000Z/20300108T160000Z');
      expect(url.queryParameters['location'], 'Roma Norte');
    });

    test('lo siguiente con algo después de una fecha', () {
      final eventos = [
        EventoCal(id: 'a', titulo: 'A', inicio: DateTime(2026, 9, 20, 10)),
        EventoCal(id: 'b', titulo: 'B', inicio: DateTime(2026, 10, 2, 9)),
      ];
      expect(
        Calendario.siguienteConAlgo(eventos, DateTime(2026, 9, 27)),
        DateTime(2026, 10, 2),
      );
      expect(
        Calendario.siguienteConAlgo(eventos, DateTime(2026, 10, 2)),
        isNull,
      );
    });
  });

  group('CalendarioVistas', () {
    final hoy = DateTime(2026, 9, 23); // miércoles
    final eventos = [
      EventoCal(
        id: 'mia',
        titulo: 'Pole Nivel 1',
        inicio: DateTime(2026, 9, 24, 9),
        destacado: true,
        estado: 'Reservada',
      ),
      EventoCal(
        id: 'libre',
        titulo: 'Exotic',
        inicio: DateTime(2026, 9, 25, 18),
        estado: '3 de 8 lugares',
      ),
    ];

    Future<List<String>> montar(
      WidgetTester tester, {
      void Function(DateTime, DateTime)? onRango,
    }) async {
      final abiertos = <String>[];
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CalendarioVistas(
              hoy: hoy,
              eventos: eventos,
              lista: const [Text('LA LISTA')],
              onAbrir: (e) => abiertos.add(e.id),
              onRango: onRango,
            ),
          ),
        ),
      );
      await tester.pump();
      return abiertos;
    }

    testWidgets('empieza en la lista y avisa su rango', (tester) async {
      final rangos = <(DateTime, DateTime)>[];
      await montar(tester, onRango: (a, b) => rangos.add((a, b)));
      expect(find.text('LA LISTA'), findsOneWidget);
      expect(rangos.single.$1, hoy);
    });

    testWidgets('en semana se ven los eventos y tocarlos los abre', (
      tester,
    ) async {
      final rangos = <(DateTime, DateTime)>[];
      final abiertos = await montar(
        tester,
        onRango: (a, b) => rangos.add((a, b)),
      );
      await tester.tap(find.text('Semana'));
      await tester.pumpAndSettle();
      expect(rangos.last, (DateTime(2026, 9, 21), DateTime(2026, 9, 27)));
      expect(find.text('Pole Nivel 1'), findsOneWidget);
      expect(find.text('Exotic'), findsOneWidget);

      await tester.tap(find.text('Pole Nivel 1'));
      expect(abiertos, ['mia']);
    });

    testWidgets('en el mes, tocar un día muestra lo de ese día', (
      tester,
    ) async {
      await montar(tester);
      await tester.tap(find.text('Mes'));
      await tester.pumpAndSettle();
      expect(find.text('Septiembre de 2026'), findsOneWidget);
      expect(find.text('Nada este día.'), findsOneWidget);

      await tester.tap(find.text('25'));
      await tester.pumpAndSettle();
      expect(find.text('Viernes 25 de septiembre'), findsOneWidget);
      expect(find.text('Exotic'), findsOneWidget);
    });

    testWidgets('un día vacío ofrece ir a lo siguiente', (tester) async {
      await montar(tester);
      await tester.tap(find.text('Día'));
      await tester.pumpAndSettle();
      expect(find.text('No hay nada en estas fechas.'), findsOneWidget);

      await tester.tap(find.text('Ver lo siguiente'));
      await tester.pumpAndSettle();
      expect(find.text('Jueves 24 de septiembre'), findsOneWidget);
      expect(find.text('Pole Nivel 1'), findsOneWidget);
    });
  });
}
