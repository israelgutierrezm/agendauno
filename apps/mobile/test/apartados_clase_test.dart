import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/apartados_clase.dart';

/// Productos al reservar (ADR 0194) en la app: lo que se ofrece para la clase se
/// aparta con un toque y lo apartado se puede quitar; sin nada que ofrecer no se
/// muestra. Datos sintéticos.

class _Repo extends CuentaRepository {
  _Repo({this.ofrece = true}) : super(Dio(), 'demo');

  final bool ofrece;
  final apartados = <ApartadoDeClase>[];
  final llamadas = <String>[];

  @override
  Future<
    ({List<ArticuloAlReservar> articulos, List<ApartadoDeClase> apartados})
  >
  articulosDeClase(String sesionId) async => (
    articulos: ofrece
        ? const [
            ArticuloAlReservar(
              id: 'agua',
              nombre: 'Agua',
              precioMinor: 2500,
              moneda: 'MXN',
              disponibles: 3,
            ),
            ArticuloAlReservar(
              id: 'toalla',
              nombre: 'Toalla',
              precioMinor: 8000,
              moneda: 'MXN',
              disponibles: 0,
            ),
          ]
        : const <ArticuloAlReservar>[],
    apartados: List.of(apartados),
  );

  @override
  Future<void> apartarArticulo(String sesionId, String articuloId) async {
    llamadas.add('apartar $sesionId $articuloId');
    apartados.add(
      const ApartadoDeClase(
        id: 'ap1',
        nombre: 'Agua',
        cantidad: 1,
        totalMinor: 2500,
        moneda: 'MXN',
      ),
    );
  }

  @override
  Future<void> quitarApartado(String apartadoId) async {
    llamadas.add('quitar $apartadoId');
    apartados.removeWhere((a) => a.id == apartadoId);
  }
}

Future<void> _montar(WidgetTester tester, _Repo repo) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [cuentaRepositoryProvider.overrideWithValue(repo)],
      child: const MaterialApp(
        home: Scaffold(body: ApartadosClase(sesionId: 's1')),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('aparta y quita un producto para recoger en la clase', (
    tester,
  ) async {
    final repo = _Repo();
    await _montar(tester, repo);
    expect(find.text('Para recoger en tu clase'), findsOneWidget);
    expect(find.text('Agotado'), findsOneWidget);

    await tester.tap(find.byKey(const Key('apartar-agua')));
    await tester.pumpAndSettle();
    expect(repo.llamadas, ['apartar s1 agua']);
    expect(find.text('1 × Agua · \$25.00'), findsOneWidget);

    await tester.tap(find.byKey(const Key('quitar-apartado-ap1')));
    await tester.pumpAndSettle();
    expect(repo.llamadas.last, 'quitar ap1');
    expect(find.text('1 × Agua · \$25.00'), findsNothing);
  });

  testWidgets('sin nada que ofrecer no se muestra', (tester) async {
    await _montar(tester, _Repo(ofrece: false));
    expect(find.byKey(const Key('apartados-clase')), findsNothing);
  });
}
