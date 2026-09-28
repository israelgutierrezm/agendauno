import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/presentation/agenda_screen.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/inicio/data/inicio_repository.dart';
import 'package:agendauno/features/inicio/data/resumen_hoy.dart';
import 'package:agendauno/features/inicio/presentation/equipo_screen.dart';

/// El Inicio del negocio en la app: el día de hoy con el estilo de los otros
/// portales (saludo, tarjeta grande con lo que sigue e indicadores, pendientes y
/// accesos) y la agenda del día en su pestaña.

class _AgendaVacia extends AgendaController {
  @override
  Future<AgendaDia> build() async =>
      const AgendaDia(sesiones: [], profesionales: []);
}

String iso(DateTime d) => d.toUtc().toIso8601String();

Map<String, dynamic> resumen() {
  final hoy = DateTime.now();
  DateTime a(int h) => DateTime(hoy.year, hoy.month, hoy.day, h);
  Map<String, dynamic> ses(
    String id,
    int h,
    String momento, {
    Map<String, dynamic> extra = const {},
  }) => {
    'id': id,
    'tipo': 'clase',
    'oferta': 'Pole Nivel $id',
    'instructor': 'Caro',
    'sucursal': 'Roma Norte',
    'cliente': null,
    'inicia_en': iso(a(h)),
    'esperados': 6,
    'sin_marcar': 0,
    'momento': momento,
    ...extra,
  };
  return {
    'fecha': '2026-09-27',
    'agenda': {
      'totales': {
        'sesiones': 3,
        'esperados': 13,
        'llegaron': 5,
        'sin_marcar': 2,
      },
      'sesiones': [
        ses('1', 8, 'termino', extra: {'sin_marcar': 2}),
        ses('2', 18, 'proxima'),
        ses(
          '3',
          19,
          'proxima',
          extra: {'tipo': 'cita', 'oferta': 'Corte', 'cliente': 'Dana'},
        ),
      ],
    },
    'cobros': {
      'ordenes_pendientes': 2,
      'por_cobrar': [
        {'moneda': 'MXN', 'total_minor': 50000},
      ],
      'en_mora': 1,
    },
    'renovaciones': {'por_vencer': 3, 'vencidas': 0, 'dias': 7},
  };
}

void main() {
  test('el resumen del día sabe qué sigue y cuánto hay por cobrar', () {
    final hoy = ResumenHoy.desdeJson(resumen());
    expect(hoy.agenda!.sesiones, 3);
    expect(hoy.agenda!.enCurso, isNull);
    expect(hoy.agenda!.siguiente!.nombre, 'Pole Nivel 2');
    expect(hoy.agenda!.lista.last.nombre, 'Corte · Dana');
    expect(hoy.cobros!.porCobrarMinor, 50000);
    expect(hoy.hayPendientes, isTrue);

    // Sin permiso de cobros ni de renovaciones, esos bloques no vienen.
    final soloAgenda = ResumenHoy.desdeJson({'fecha': 'x', 'agenda': null});
    expect(soloAgenda.agenda, isNull);
    expect(soloAgenda.hayPendientes, isFalse);
  });

  testWidgets('el Inicio del negocio: el día con el estilo del portal', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(
            const Sesion(
              slug: 'demo',
              bearer: 't',
              nombre: 'Israel Gutiérrez',
              nombrePila: 'Israel',
              rol: 'propietario',
              permisos: ['*'],
              estudioNombre: 'Estudio Demo',
            ),
          ),
          resumenHoyProvider.overrideWith(
            (ref) async => ResumenHoy.desdeJson(resumen()),
          ),
          climaNegocioProvider.overrideWith(
            (ref) async => const ClimaMiembro(
              tipo: 'ahora',
              lugar: 'Roma Norte',
              aproximado: false,
              temperatura: 23,
              condicion: 'Despejado',
              icono: 'despejado',
              esDeDia: true,
            ),
          ),
          agendaProvider.overrideWith(_AgendaVacia.new),
        ],
        child: const MaterialApp(home: EquipoScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('¡Hola, Israel!'), findsOneWidget);
    expect(
      find.text('Esto es lo que pasa hoy en Estudio Demo.'),
      findsOneWidget,
    );
    expect(find.text('LO QUE SIGUE HOY'), findsOneWidget);
    expect(find.text('Pole Nivel 2'), findsOneWidget);
    expect(find.text('Por pasar lista'), findsOneWidget);
    expect(find.text('Ahora en Roma Norte'), findsOneWidget);
    expect(find.text(r'2 órdenes por cobrar · $500.00'), findsOneWidget);
    expect(find.text('1 cuenta en mora'), findsOneWidget);
    expect(find.text('3 membresías vencen en 7 días o menos'), findsOneWidget);

    // "Abrir agenda" lleva a la agenda del día.
    await tester.tap(find.text('Abrir agenda'));
    await tester.pumpAndSettle();
    expect(find.byType(AgendaScreen), findsOneWidget);
  });

  testWidgets('un rol propio sin ver agenda ni pasar lista solo tiene su Inicio', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    Future<void> montar(List<String> permisos) => tester.pumpWidget(
      ProviderScope(
        key: UniqueKey(),
        overrides: [
          sesionInicialProvider.overrideWithValue(
            Sesion(
              slug: 'demo',
              bearer: 't',
              nombre: 'Coordinación',
              rol: 'rol_coordinacion',
              permisos: permisos,
            ),
          ),
          // Sin agenda.ver el servidor no manda el bloque de la agenda.
          resumenHoyProvider.overrideWith(
            (ref) async => ResumenHoy.desdeJson({'fecha': 'x', 'agenda': null}),
          ),
          climaNegocioProvider.overrideWith((ref) async => null),
          agendaProvider.overrideWith(_AgendaVacia.new),
        ],
        child: const MaterialApp(home: EquipoScreen()),
      ),
    );

    await montar(['miembros.ver']);
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsNothing);
    expect(find.text('Agenda del día'), findsNothing);
    expect(find.text('Pasar lista'), findsNothing);
    expect(find.text('Mi perfil'), findsOneWidget);

    // Con ver agenda, sí su pestaña; pasar lista pide además ver reservas y marcar.
    await montar(['agenda.ver']);
    await tester.pumpAndSettle();
    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.text('Agenda del día'), findsOneWidget);
    expect(find.text('Pasar lista'), findsNothing);
  });
}
