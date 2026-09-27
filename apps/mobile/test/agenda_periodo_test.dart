import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:turnouno_mobile/features/auth/application/sesion_controller.dart';
import 'package:turnouno_mobile/features/auth/data/sesion.dart';
import 'package:turnouno_mobile/features/cuenta/application/cuenta_controller.dart';
import 'package:turnouno_mobile/features/cuenta/data/cuenta_models.dart';
import 'package:turnouno_mobile/features/cuenta/data/cuenta_repository.dart';
import 'package:turnouno_mobile/features/cuenta/presentation/reservas_tab.dart';

/// El calendario de Reservas pide las clases del PERIODO que ve (y de la sede que
/// elija), las vuelve a pedir al moverse y, si falla, lo dice con "Reintentar" en
/// vez de mostrar un periodo vacío.

const _vacia = MiCuenta(derechos: [], reservas: [], clases: []);

class _Cuenta extends CuentaController {
  @override
  Future<MiCuenta> build() async => _vacia;
}

/// Repositorio falso: anota cada periodo pedido; puede fallar.
class _Repo extends CuentaRepository {
  _Repo() : super(Dio(), 'demo');

  final pedidos = <String>[];
  bool falla = false;

  @override
  Future<AgendaPeriodo> agendaPeriodo(
    DateTime desde,
    DateTime hasta, {
    String? sucursalId,
  }) async {
    pedidos.add(
      '${desde.day}/${desde.month}-${hasta.day}/${hasta.month}|${sucursalId ?? ''}',
    );
    if (falla) {
      throw DioException(requestOptions: RequestOptions(path: '/mi/agenda'));
    }
    return AgendaPeriodo(
      clases: [
        ClaseMiembro(
          id: 'c1',
          oferta: 'Pole Nivel 1',
          sucursal: 'Condesa',
          iniciaEn: DateTime.now()
              .add(const Duration(days: 1))
              .toUtc()
              .toIso8601String(),
          capacidad: 10,
        ),
      ],
      sucursales: const [
        SedeAgenda(id: 's1', nombre: 'Roma Norte'),
        SedeAgenda(id: 's2', nombre: 'Condesa'),
      ],
    );
  }
}

void main() {
  test('cambiar de periodo o de sede vuelve a pedir las clases', () async {
    final repo = _Repo();
    final c = ProviderContainer(
      overrides: [
        sesionInicialProvider.overrideWithValue(
          const Sesion(
            slug: 'demo',
            bearer: 't',
            nombre: 'Ana',
            rol: 'miembro',
          ),
        ),
        cuentaProvider.overrideWith(_Cuenta.new),
        cuentaRepositoryProvider.overrideWithValue(repo),
      ],
      retry: (_, _) => null,
    );
    addTearDown(c.dispose);
    final pantalla = c.listen(clasesPeriodoProvider, (_, _) {});
    await c.read(clasesPeriodoProvider.future);

    c
        .read(filtroAgendaProvider.notifier)
        .fijarPeriodo(DateTime(2030, 3, 4), DateTime(2030, 3, 10));
    await c.read(clasesPeriodoProvider.future);
    c.read(filtroAgendaProvider.notifier).elegirSucursal('s2');
    await c.read(clasesPeriodoProvider.future);

    expect(repo.pedidos.skip(1), ['4/3-10/3|', '4/3-10/3|s2']);
    pantalla.close();
  });

  testWidgets('si falla la carga del periodo se ve el error con reintentar', (
    tester,
  ) async {
    final repo = _Repo()..falla = true;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(
            const Sesion(
              slug: 'demo',
              bearer: 't',
              nombre: 'Ana',
              rol: 'miembro',
            ),
          ),
          cuentaProvider.overrideWith(_Cuenta.new),
          cuentaRepositoryProvider.overrideWithValue(repo),
        ],
        retry: (_, _) => null,
        child: const MaterialApp(
          home: Scaffold(body: ReservasTab(cuenta: _vacia)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.text('No se pudieron cargar las clases de estas fechas.'),
      findsOneWidget,
    );
    expect(find.textContaining('No hay clases programadas'), findsNothing);

    repo.falla = false;
    await tester.tap(find.text('Reintentar'));
    await tester.pumpAndSettle();
    expect(find.text('Pole Nivel 1'), findsOneWidget);
    // Con más de una sede, se puede elegir.
    expect(find.text('Todas las sucursales'), findsOneWidget);
  });
}
