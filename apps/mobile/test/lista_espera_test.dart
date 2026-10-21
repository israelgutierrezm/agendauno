import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/agenda/presentation/pase_lista_screen.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// El pase de lista en la app, como en la web: la lista de espera se ve en su
/// orden, «Ofrecer lugares» aparece con lugares libres y se confirma, y el lugar
/// ofrecido se acepta a nombre de la persona. Datos sintéticos.

class _Repo extends AgendaRepository {
  _Repo({this.empezo = false}) : super(Dio(), 'demo');

  /// Si la clase ya empezó (lo dice el servidor en `meta.empezo`).
  final bool empezo;
  final hechas = <String>[];

  @override
  Future<ListaClase> roster(String sesionId) async => ListaClase.desdeJson({
    'data': [
      {'id': 'r1', 'persona': 'Ana López', 'estado': 'confirmada'},
      {'id': 'r2', 'persona': 'Beto Ruiz', 'estado': 'ofrecida'},
      {'id': 'r3', 'persona': 'Caro Díaz', 'estado': 'en_espera'},
      {'id': 'r4', 'persona': 'Dani Paz', 'estado': 'en_espera'},
    ],
    'meta': {'empezo': empezo},
  });

  @override
  Future<int> promover(String sesionId) async {
    hechas.add('promover');
    return 1;
  }

  @override
  Future<void> aceptarLugar(String reservaId) async =>
      hechas.add('aceptar:$reservaId');
}

final _clase = SesionAgenda.desdeJson({
  'id': 's1',
  'tipo': 'clase',
  'oferta': 'Pole Nivel 1',
  'inicia_en': DateTime.now().toUtc().toIso8601String(),
  'termina_en': DateTime.now()
      .add(const Duration(hours: 1))
      .toUtc()
      .toIso8601String(),
  // Cuatro lugares: dos ocupados, dos libres.
  'capacidad': 4,
  'ocupados': 2,
  'en_espera': 2,
  'estado': 'programada',
});

Future<void> _abrir(WidgetTester tester, _Repo repo) async {
  tester.view.physicalSize = const Size(420, 1000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(
          const Sesion(
            slug: 'demo',
            bearer: 't',
            nombre: 'Recepción',
            rol: 'recepcionista',
            permisos: ['*'],
          ),
        ),
        agendaRepositoryProvider.overrideWithValue(repo),
      ],
      child: MaterialApp(home: PaseListaScreen(sesion: _clase)),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('la lista de espera en su orden, ofrecer lugares y aceptar', (
    tester,
  ) async {
    final repo = _Repo();
    await _abrir(tester, repo);

    expect(find.text('Lista de espera · 2'), findsOneWidget);
    expect(find.text('1. Caro Díaz'), findsOneWidget);
    expect(find.text('2. Dani Paz'), findsOneWidget);
    expect(find.text('Lugar ofrecido'), findsOneWidget);

    // Ofrecer lugares se confirma antes.
    await tester.tap(find.text('Ofrecer lugares'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ofrecer lugares').last);
    await tester.pumpAndSettle();
    expect(
      find.text('Se ofreció 1 lugar a la lista de espera.'),
      findsOneWidget,
    );

    await tester.tap(find.text('Aceptar'));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('¿Aceptar el lugar a nombre de Beto Ruiz?'),
      findsOneWidget,
    );
    await tester.tap(find.text('Aceptar el lugar'));
    await tester.pumpAndSettle();

    expect(repo.hechas, ['promover', 'aceptar:r2']);
  });

  // El servidor ya no ofrece ni acepta lugares de una clase empezada (ADR 0095):
  // la app no ofrece botones que solo darían un error.
  testWidgets('empezada la clase, ni «Ofrecer lugares» ni «Aceptar»', (
    tester,
  ) async {
    await _abrir(tester, _Repo(empezo: true));

    expect(find.text('Lista de espera · 2'), findsOneWidget);
    expect(find.text('Lugar ofrecido'), findsOneWidget);
    expect(find.text('Ofrecer lugares'), findsNothing);
    expect(find.text('Aceptar'), findsNothing);
  });
}
