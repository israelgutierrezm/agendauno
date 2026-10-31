import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/presentation/cita_sheet.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_widgets.dart';
import 'package:agendauno/features/cuenta/presentation/pase_sheet.dart';

/// Las hojas reales en un teléfono chico (375×667, iPhone SE): lo de abajo se alcanza
/// y se puede tocar, y un error se ve de verdad (`hitTestable`: un SnackBar quedaría
/// detrás de la hoja aunque `find.text` lo encuentre).

DioException _rechazo(String mensaje) => DioException(
  requestOptions: RequestOptions(path: '/x'),
  response: Response(
    requestOptions: RequestOptions(path: '/x'),
    statusCode: 422,
    data: {'message': mensaje},
  ),
);

final _clase = ClaseMiembro(
  id: 's1',
  oferta: 'Pole Nivel 1',
  sucursal: 'Roma Norte',
  iniciaEn: DateTime.now()
      .add(const Duration(days: 1))
      .toUtc()
      .toIso8601String(),
  capacidad: 20,
  ocupados: 3,
);

final _conVeinteLugares = AntesDeReservar.desdeJson({
  'resultado': 'reserva',
  'lugares': {
    'total': 20,
    'ocupados': [2, 7],
  },
  'cancelacion': {
    'limite': DateTime.now()
        .add(const Duration(hours: 20))
        .toUtc()
        .toIso8601String(),
  },
});

class _Cuenta extends CuentaController {
  _Cuenta(this.alReservar);

  final Future<({String estado, String? ordenId})> Function(int? lugar)
  alReservar;

  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);

  @override
  Future<({String estado, String? ordenId})> reservar(
    String sesionId, {
    bool esperar = false,
    int? lugar,
  }) => alReservar(lugar);
}

void _telefonoChico(WidgetTester tester) {
  tester.view.physicalSize = const Size(375, 667);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<void> _abrirClase(
  WidgetTester tester,
  Future<({String estado, String? ordenId})> Function(int? lugar) alReservar,
) async {
  _telefonoChico(tester);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        cuentaProvider.overrideWith(() => _Cuenta(alReservar)),
        antesDeReservarProvider.overrideWith(
          (ref, id) async => _conVeinteLugares,
        ),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () => mostrarDetalleClase(context, _clase),
              child: const Text('Abrir'),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('Abrir'));
  await tester.pumpAndSettle();
}

class _RepoPase extends CuentaRepository {
  _RepoPase() : super(Dio(), 'demo');

  @override
  Future<String> pase() async => 'AU1.codigo';

  @override
  Future<({bool apple, bool google})> walletDisponible() async =>
      (apple: false, google: true);

  @override
  Future<void> renovarPaseWallet() async => throw _rechazo('sin red');
}

Map<String, dynamic> _citaPorCobrar() {
  final ahora = DateTime.now();
  return {
    'id': 's1',
    'tipo': 'cita',
    'oferta': 'Corte',
    'inicia_en': ahora.toUtc().toIso8601String(),
    'termina_en': ahora.add(const Duration(hours: 1)).toUtc().toIso8601String(),
    'capacidad': 1,
    'ocupados': 1,
    'en_espera': 0,
    'estado': 'programada',
    'cita': {
      'reserva_id': 'r1',
      'cliente': 'Ana López',
      'estado': 'confirmada',
      'estado_atencion': 'confirmada',
      'orden_id': 'o1',
      'estado_pago': 'por_cobrar',
      'total_minor': 25000,
      'por_cobrar_minor': 25000,
      'moneda': 'MXN',
    },
  };
}

class _Agenda extends AgendaController {
  @override
  Future<AgendaDia> build() async => AgendaDia(
    sesiones: [SesionAgenda.desdeJson(_citaPorCobrar())],
    profesionales: const [],
  );

  @override
  Future<bool> cobrar(
    SesionAgenda sesion,
    String metodo, {
    String? clave,
  }) async => throw _rechazo('Abre la caja antes de cobrar.');
}

void main() {
  testWidgets('con 20 lugares se llega a «Confirmar reserva» y se puede tocar', (
    tester,
  ) async {
    int? reservado;
    await _abrirClase(tester, (lugar) async {
      reservado = lugar;
      return (estado: 'confirmada', ordenId: null);
    });

    await tester.ensureVisible(find.text('20'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('20'));
    await tester.pumpAndSettle();

    final confirmar = find.byKey(const Key('confirmar-reserva'));
    await tester.ensureVisible(confirmar);
    await tester.pumpAndSettle();
    expect(confirmar.hitTestable(), findsOneWidget);
    await tester.tap(confirmar);
    await tester.pumpAndSettle();

    expect(reservado, 20);
    expect(find.text('Reservado.').hitTestable(), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('si no se pudo reservar, el error se ve en la hoja', (
    tester,
  ) async {
    await _abrirClase(
      tester,
      (_) async => throw _rechazo('Alguien tomó ese lugar.'),
    );

    final confirmar = find.byKey(const Key('confirmar-reserva'));
    await tester.ensureVisible(confirmar);
    await tester.pumpAndSettle();
    await tester.tap(confirmar);
    await tester.pumpAndSettle();

    expect(find.text('Alguien tomó ese lugar.').hitTestable(), findsOneWidget);
  });

  testWidgets('en el pase se alcanza «Renovar» y su error se ve en la hoja', (
    tester,
  ) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    _telefonoChico(tester);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [cuentaRepositoryProvider.overrideWithValue(_RepoPase())],
        child: MaterialApp(
          home: Scaffold(
            body: Builder(
              builder: (context) => TextButton(
                onPressed: () => mostrarPase(context),
                child: const Text('Abrir'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Abrir'));
    await tester.pumpAndSettle();

    final renovar = find.byKey(const Key('pase-renovar-wallet'));
    await tester.ensureVisible(renovar);
    await tester.pumpAndSettle();
    expect(renovar.hitTestable(), findsOneWidget);
    await tester.tap(renovar);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Renovarlo'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('pase-aviso-wallet')));
    await tester.pumpAndSettle();

    expect(
      find.text('No se pudo renovar tu pase.').hitTestable(),
      findsOneWidget,
    );
    // Se desmonta para que pare el temporizador del QR.
    await tester.pumpWidget(const SizedBox());
    debugDefaultTargetPlatformOverride = null;
  });

  testWidgets('si no se pudo cobrar la cita, el error se ve en la hoja', (
    tester,
  ) async {
    _telefonoChico(tester);
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
              modalidad: Modalidad.citas,
            ),
          ),
          agendaProvider.overrideWith(_Agenda.new),
        ],
        child: MaterialApp(
          home: Scaffold(
            body: Consumer(
              builder: (context, ref, _) {
                final agenda = ref.watch(agendaProvider).value;
                return TextButton(
                  onPressed: agenda == null
                      ? null
                      : () => mostrarHojaCita(context, agenda.sesiones.first),
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

    final cobrar = find.widgetWithText(FilledButton, 'Cobrar \$250.00');
    await tester.ensureVisible(cobrar);
    await tester.pumpAndSettle();
    await tester.tap(cobrar);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(TextButton, 'Cobrar \$250.00'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('cita-error')));
    await tester.pumpAndSettle();
    expect(
      find.text('Abre la caja antes de cobrar.').hitTestable(),
      findsOneWidget,
    );
  });
}
