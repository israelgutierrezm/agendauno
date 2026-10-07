import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/corte_planes.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_screen.dart';
import 'package:agendauno/features/instructor/application/mis_clases_controller.dart';
import 'package:agendauno/features/instructor/presentation/instructor_screen.dart';

/// Las pantallas completas de ambos portales se arman en un teléfono sin
/// desbordes ni errores, y la barra de abajo lleva a cada parte.

String iso(DateTime d) => d.toUtc().toIso8601String();

class _CuentaFalsa extends CuentaController {
  _CuentaFalsa(this.cuenta);

  final MiCuenta cuenta;

  @override
  Future<MiCuenta> build() async => cuenta;
}

Future<void> montar(
  WidgetTester tester,
  Widget pantalla,
  List overrides,
) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [...overrides],
      child: MaterialApp(home: pantalla),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  final manana = DateTime.now().add(const Duration(days: 1));
  final inicio = DateTime(manana.year, manana.month, manana.day, 9);

  testWidgets('portal del alumno: inicio con accesos y cada pestaña', (
    tester,
  ) async {
    final cuenta = MiCuenta(
      derechos: const [
        DerechoMiembro(
          ilimitado: false,
          id: 'd1',
          disponible: 6000,
          producto: 'Pack 8 clases',
        ),
      ],
      reservas: [
        ReservaMiembro(
          id: 'r1',
          estado: 'confirmada',
          tipo: TipoSesion.clase,
          sesionId: 's1',
          oferta: 'Pole Nivel 1',
          sucursal: 'Roma Norte',
          iniciaEn: iso(inicio),
          terminaEn: iso(inicio.add(const Duration(hours: 1))),
        ),
      ],
      clases: [
        ClaseMiembro(
          id: 's2',
          oferta: 'Exotic con un nombre muy largo para ver que no se desborda',
          sucursal: 'Condesa',
          iniciaEn: iso(inicio.add(const Duration(hours: 3))),
          capacidad: 8,
          ocupados: 8,
          // Los lugares libres los cuenta el servidor.
          clase: BloqueClase(capacidad: 8, ocupados: 8, libres: 0),
        ),
      ],
      consentimientos: const [
        ConsentimientoPendiente(id: 'w1', titulo: 'Reglamento', contenido: '…'),
      ],
      porPagar: const [
        OrdenPorPagar(id: 'o1', concepto: 'Pack 8 clases', totalMinor: 50000),
      ],
    );
    await montar(tester, const CuentaScreen(), [
      sesionInicialProvider.overrideWithValue(
        const Sesion(slug: 'demo', bearer: 't', nombre: 'Vale', rol: 'miembro'),
      ),
      cuentaProvider.overrideWith(() => _CuentaFalsa(cuenta)),
      climaProvider.overrideWith(
        (ref) async => const ClimaMiembro(
          tipo: 'pronostico',
          lugar: 'Roma Norte',
          aproximado: false,
          temperatura: 16,
          condicion: 'Lluvia',
          icono: 'lluvia',
          esDeDia: true,
          lluvia: 70,
        ),
      ),
      // Las clases del periodo que ve el calendario de Reservas.
      clasesPeriodoProvider.overrideWith(
        (ref) async => AgendaPeriodo(clases: cuenta.clases),
      ),
      cortePlanesProvider.overrideWith(
        (ref) async => [
          PlanCorte.desdeJson({
            'id': 'a1',
            'derecho_id': 'd1',
            'producto': 'Pack 8 clases',
            'desde': '2026-10-14',
            'hasta': '2026-11-14',
            'estado': 'vigente',
            'ilimitado': false,
            'aplica_a': ['Nivel 1'],
            'unidades': {
              'incluidas': 8000,
              'usadas': 2000,
              'disponibles': 6000,
            },
            'usos': [
              {
                'clase': 'Nivel 1',
                'inicia_en': '2026-10-16T01:00:00Z',
                'estado': 'asistio',
              },
            ],
          }),
        ],
      ),
    ]);

    expect(find.text('¡Hola, Vale!'), findsOneWidget);
    expect(find.text('TU PRÓXIMA CLASE'), findsOneWidget);
    expect(find.text('Pole Nivel 1'), findsOneWidget);
    expect(find.text('Pronóstico para tu clase en Roma Norte'), findsOneWidget);
    expect(find.textContaining('70 % de lluvia'), findsOneWidget);
    expect(find.text('Agregar a mi calendario'), findsOneWidget);
    expect(find.text('Tienes 1 documento por firmar'), findsOneWidget);
    expect(find.text('6 créditos'), findsOneWidget);

    // Cada pestaña de la barra de abajo.
    await tester.tap(find.text('Reservas').last);
    await tester.pumpAndSettle();
    expect(find.text('Mis reservas'), findsWidgets);
    expect(find.text('Llena'), findsOneWidget);
    await tester.tap(find.text('Mes'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Semana'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Pagos').last);
    await tester.pumpAndSettle();
    expect(find.text('Por pagar'), findsOneWidget);
    expect(find.text('Mis planes'), findsOneWidget);
    expect(find.text('Pack 8 clases'), findsWidgets);
    expect(find.textContaining('Sirve para: Nivel 1'), findsOneWidget);
    await tester.tap(find.text('Cómo lo usaste (1)'));
    await tester.pumpAndSettle();
    expect(find.text('Asistió'), findsOneWidget);

    await tester.tap(find.text('Expediente').last);
    await tester.pumpAndSettle();
    expect(find.text('Reglamento'), findsOneWidget);

    await tester.tap(find.text('Cuenta').last);
    await tester.pumpAndSettle();
    expect(find.text('Privacidad y mis datos'), findsOneWidget);
  });

  testWidgets(
    'sin reserva la tarjeta principal sigue ahí e invita a reservar',
    (tester) async {
      await montar(tester, const CuentaScreen(), [
        sesionInicialProvider.overrideWithValue(
          const Sesion(
            slug: 'demo',
            bearer: 't',
            nombre: 'Vale Ruiz',
            rol: 'miembro',
            estudioNombre: 'Estudio Demo',
          ),
        ),
        cuentaProvider.overrideWith(
          () => _CuentaFalsa(
            const MiCuenta(derechos: [], reservas: [], clases: []),
          ),
        ),
        climaProvider.overrideWith((ref) async => null),
        cortePlanesProvider.overrideWith((ref) async => const []),
      ]);

      expect(find.text('¡Hola, Vale!'), findsOneWidget);
      expect(
        find.text('Aquí tienes un resumen de tu actividad en Estudio Demo.'),
        findsOneWidget,
      );
      // Sin reserva, el término general.
      expect(find.text('TU PRÓXIMA RESERVA'), findsOneWidget);
      expect(find.text('Nada agendado por ahora'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Reservar'), findsOneWidget);
      expect(find.text('Agregar a mi calendario'), findsNothing);
    },
  );

  testWidgets('una cita se nombra como cita, también en el pronóstico', (
    tester,
  ) async {
    final manana = DateTime.now().add(const Duration(days: 1));
    final cita = DateTime(manana.year, manana.month, manana.day, 10);
    await montar(tester, const CuentaScreen(), [
      sesionInicialProvider.overrideWithValue(
        const Sesion(slug: 'demo', bearer: 't', nombre: 'Ana', rol: 'miembro'),
      ),
      cuentaProvider.overrideWith(
        () => _CuentaFalsa(
          MiCuenta(
            derechos: const [],
            reservas: [
              ReservaMiembro(
                id: 'r1',
                estado: 'confirmada',
                tipo: TipoSesion.cita,
                oferta: 'Corte de cabello',
                sucursal: 'Roma Norte',
                iniciaEn: iso(cita),
              ),
            ],
            clases: const [],
          ),
        ),
      ),
      climaProvider.overrideWith(
        (ref) async => const ClimaMiembro(
          tipo: 'pronostico',
          lugar: 'Roma Norte',
          aproximado: false,
          temperatura: 16,
          condicion: 'Lluvia',
          icono: 'lluvia',
          esDeDia: true,
        ),
      ),
      cortePlanesProvider.overrideWith((ref) async => const []),
    ]);

    expect(find.text('TU PRÓXIMA CITA'), findsOneWidget);
    expect(find.text('TU PRÓXIMA CLASE'), findsNothing);
    expect(find.text('Corte de cabello'), findsOneWidget);
    expect(find.text('Pronóstico para tu cita en Roma Norte'), findsOneWidget);
  });

  testWidgets('portal del instructor: inicio y mis clases', (tester) async {
    SesionAgenda clase(String id, DateTime cuando, {int espera = 0}) =>
        SesionAgenda.desdeJson({
          'id': id,
          'tipo': 'clase',
          'oferta': 'Pole Nivel $id',
          'sala': 'Sala A',
          'sucursal': 'Roma Norte',
          'inicia_en': iso(cuando),
          'termina_en': iso(cuando.add(const Duration(hours: 1))),
          'capacidad': 10,
          'ocupados': 6,
          'en_espera': espera,
          'estado': 'programada',
        });
    final sesiones = [
      clase('1', inicio, espera: 2),
      clase('2', inicio.add(const Duration(days: 2))),
    ];
    await montar(tester, const InstructorScreen(), [
      sesionInicialProvider.overrideWithValue(
        const Sesion(
          slug: 'demo',
          bearer: 't',
          nombre: 'Mariana',
          rol: 'instructor',
        ),
      ),
      proximasMisClasesProvider.overrideWith((ref) async => sesiones),
      misClasesProvider.overrideWith((ref) async => sesiones),
      climaEquipoProvider.overrideWith(
        (ref) async => const ClimaMiembro(
          tipo: 'pronostico',
          lugar: 'Roma Norte',
          aproximado: false,
          temperatura: 21,
          condicion: 'Parcialmente nublado',
          icono: 'parcial',
          esDeDia: true,
        ),
      ),
    ]);

    // El mismo estilo del Inicio del alumno: saludo, tarjeta grande y clima.
    expect(find.text('¡Hola, Mariana!'), findsOneWidget);
    expect(find.text('TU PRÓXIMA CLASE'), findsOneWidget);
    expect(find.text('Pronóstico para tu clase en Roma Norte'), findsOneWidget);
    expect(find.text('Pole Nivel 1'), findsOneWidget);
    expect(
      find.text('6 de 10 lugares ocupados · 2 en lista de espera'),
      findsOneWidget,
    );
    expect(find.text('Pasar lista'), findsOneWidget);
    expect(find.text('2 clases'), findsOneWidget); // próximos 7 días

    // "Mi calendario" abre Mis clases en el mes (el acceso está bajo la tarjeta).
    await tester.ensureVisible(find.text('Mi calendario'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Mi calendario'));
    await tester.pumpAndSettle();
    expect(find.text('Mis clases'), findsWidgets);
    await tester.tap(find.text('Lista'));
    await tester.pumpAndSettle();
    expect(find.text('Próximas clases'), findsOneWidget);

    // Tocar una clase abre su detalle con pase de lista y calendario.
    await tester.tap(find.text('Pole Nivel 2'));
    await tester.pumpAndSettle();
    expect(find.text('Pasar lista'), findsOneWidget);
    expect(find.text('Agregar a mi calendario'), findsOneWidget);
  });
}
