import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/presentation/agenda_screen.dart';
import 'package:agendauno/features/agenda/presentation/cita_sheet.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// La agenda de citas del equipo en la app, como en la web: quien imparte abre su
/// propio día, lo que se cobra es el total de la cita (no el precio de hoy), lo que
/// mueve dinero o créditos se confirma, la asistencia respeta su ventana y la
/// semana se recorre con flechas. Datos sintéticos.

const _nb = ' ';

Map<String, dynamic> _cita({
  required String id,
  required String instructorId,
  required String instructor,
  DateTime? inicia,
  DateTime? asistenciaDesde,
  Map<String, dynamic> extra = const {},
}) {
  final ini = inicia ?? DateTime.now();
  return {
    'id': id,
    'tipo': 'cita',
    'oferta': 'Corte',
    'oferta_id': 'o1',
    // El precio de hoy en el catálogo; la cita se agendó a otro.
    'oferta_precio_clase': 1250,
    'instructor': instructor,
    'instructor_id': instructorId,
    'inicia_en': ini.toUtc().toIso8601String(),
    'termina_en': ini.add(const Duration(hours: 1)).toUtc().toIso8601String(),
    'asistencia_desde': asistenciaDesde?.toUtc().toIso8601String(),
    'capacidad': 1,
    'ocupados': 1,
    'en_espera': 0,
    'estado': 'programada',
    'cita': {
      'reserva_id': 'r-$id',
      'cliente': 'Ana López',
      'estado': 'confirmada',
      'orden_id': 'o-$id',
      'por_cobrar': true,
      'estado_atencion': 'confirmada',
      'estado_pago': 'por_cobrar',
      'total_minor': 1500,
      'moneda': 'MXN',
      ...extra,
    },
  };
}

const _equipo = [
  Profesional(id: 'p1', nombre: 'Alma Ruiz'),
  Profesional(id: 'p2', nombre: 'Beto Ramírez'),
];

/// Lo que el controlador hizo (para afirmarlo sin red).
final _hechas = <String>[];

class _Agenda extends AgendaController {
  _Agenda(this._sesiones);

  final List<Map<String, dynamic>> _sesiones;

  @override
  Future<AgendaDia> build() async => AgendaDia(
    sesiones: _sesiones.map(SesionAgenda.desdeJson).toList(),
    profesionales: _equipo,
  );

  @override
  Future<void> cobrar(SesionAgenda s, String metodo) async =>
      _hechas.add('cobrar:$metodo');

  @override
  Future<void> marcarNoAsistio(SesionAgenda s) async =>
      _hechas.add('no_asistio');

  @override
  Future<void> marcarLlegada(SesionAgenda s, {bool retardo = false}) async =>
      _hechas.add('llego:$retardo');
}

const _recepcion = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Recepción',
  rol: 'propietario',
  permisos: ['*'],
  modalidad: Modalidad.citas,
);

/// Quien imparte (TurnoUno): el servidor solo le da sus citas.
const _beto = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Beto Ramírez',
  rol: 'instructor',
  permisos: ['agenda.ver', 'reservas.ver', 'asistencia.marcar'],
  modalidad: Modalidad.citas,
);

Future<void> _abrirAgenda(
  WidgetTester tester,
  Sesion sesion,
  List<Map<String, dynamic>> sesiones,
) async {
  tester.view.physicalSize = const Size(600, 1000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(sesion),
        agendaProvider.overrideWith(() => _Agenda(sesiones)),
      ],
      child: const MaterialApp(home: AgendaScreen()),
    ),
  );
  await tester.pump();
  await tester.pump();
}

Future<void> _abrirHoja(
  WidgetTester tester,
  Map<String, dynamic> cita, {
  Sesion sesion = _recepcion,
}) async {
  tester.view.physicalSize = const Size(600, 1200);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final s = SesionAgenda.desdeJson(cita);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(sesion),
        agendaProvider.overrideWith(() => _Agenda([cita])),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: Consumer(
            builder: (context, ref, _) {
              ref.watch(agendaProvider);
              return TextButton(
                onPressed: () => mostrarHojaCita(context, s),
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
}

void main() {
  setUp(_hechas.clear);

  testWidgets('quien imparte abre su propio día, sin elegir a otros', (
    tester,
  ) async {
    await _abrirAgenda(tester, _beto, [
      _cita(id: 'c1', instructorId: 'p2', instructor: 'Beto Ramírez'),
    ]);

    // Antes abría a «Alma» (la primera del equipo) con «Sin citas este día.».
    expect(find.text('Sin citas este día.'), findsNothing);
    expect(find.text('Ana López'), findsOneWidget);
    expect(find.byType(ChoiceChip), findsNothing);
  });

  testWidgets('recepción abre al primer profesional con citas ese día', (
    tester,
  ) async {
    await _abrirAgenda(tester, _recepcion, [
      _cita(id: 'c1', instructorId: 'p2', instructor: 'Beto Ramírez'),
    ]);

    expect(find.byType(ChoiceChip), findsNWidgets(2));
    expect(find.text('Ana López'), findsOneWidget);
    // Por cobrar: el total de la cita, no el precio de hoy en el catálogo.
    expect(find.text('\$15.00'), findsOneWidget);
  });

  testWidgets('las flechas recorren las semanas y «Hoy» regresa', (
    tester,
  ) async {
    await _abrirAgenda(tester, _recepcion, const []);
    final hoy = DateTime.now();
    final en7 = hoy.add(const Duration(days: 7));

    expect(find.text('Hoy'), findsNothing);
    await tester.tap(find.byTooltip('Semana siguiente'));
    await tester.pump();
    expect(find.textContaining('${en7.day} de'), findsOneWidget);
    expect(find.text('Hoy'), findsOneWidget);

    await tester.tap(find.text('Hoy'));
    await tester.pump();
    expect(find.text('Hoy'), findsNothing);
    expect(find.textContaining('${hoy.day} de'), findsOneWidget);
  });

  testWidgets('cobrar usa el total de la cita y se confirma antes', (
    tester,
  ) async {
    await _abrirHoja(
      tester,
      _cita(id: 'c1', instructorId: 'p2', instructor: 'Beto Ramírez'),
    );

    expect(find.text('Cobrar \$15.00'), findsOneWidget);
    expect(find.textContaining('MXN${_nb}12.50'), findsNothing);

    // Volver no cobra.
    await tester.tap(find.text('Cobrar \$15.00'));
    await tester.pumpAndSettle();
    expect(
      find.text(
        '¿Registrar el cobro de \$15.00 en Efectivo a Ana López? La cita '
        'queda pagada.',
      ),
      findsOneWidget,
    );
    await tester.tap(find.text('Volver'));
    await tester.pumpAndSettle();
    expect(_hechas, isEmpty);

    await tester.tap(find.text('Cobrar \$15.00'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cobrar \$15.00').last);
    await tester.pumpAndSettle();
    expect(_hechas, ['cobrar:efectivo']);
  });

  testWidgets('«No asistió» se confirma con su efecto', (tester) async {
    await _abrirHoja(
      tester,
      _cita(id: 'c1', instructorId: 'p2', instructor: 'Beto Ramírez'),
    );

    await tester.tap(find.text('No asistió'));
    await tester.pumpAndSettle();
    expect(
      find.textContaining('puede perder el crédito o el pago'),
      findsOneWidget,
    );
    await tester.tap(find.text('No asistió').last);
    await tester.pumpAndSettle();
    expect(_hechas, ['no_asistio']);
  });

  testWidgets('antes de su ventana, la asistencia no se registra', (
    tester,
  ) async {
    final en2h = DateTime.now().add(const Duration(hours: 2));
    final abre = en2h.subtract(const Duration(minutes: 15));
    await _abrirHoja(
      tester,
      _cita(
        id: 'c1',
        instructorId: 'p2',
        instructor: 'Beto Ramírez',
        inicia: en2h,
        asistenciaDesde: abre,
      ),
    );

    final hh =
        '${abre.hour.toString().padLeft(2, '0')}:'
        '${abre.minute.toString().padLeft(2, '0')}';
    expect(
      find.text('La asistencia se registra desde las $hh.'),
      findsOneWidget,
    );
    final llego = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Llegó'),
    );
    expect(llego.onPressed, isNull);
  });

  testWidgets('la hoja dice lo que pidió el cliente y quién asiste', (
    tester,
  ) async {
    await _abrirHoja(
      tester,
      _cita(
        id: 'c1',
        instructorId: 'p2',
        instructor: 'Beto Ramírez',
        extra: {
          'nota': 'Solo las puntas, por favor.',
          'asiste': 'Sofía López',
          'telefono': '55 1234 5678',
        },
      ),
    );

    expect(find.text('Solo las puntas, por favor.'), findsOneWidget);
    expect(find.text('Asiste: Sofía López'), findsOneWidget);
    expect(find.text('55 1234 5678'), findsOneWidget);
    expect(find.text('WhatsApp'), findsOneWidget);
  });

  test('el historial de una cita se lee como en la web', () {
    HechoCita h(String tipo, Map<String, dynamic> d) =>
        HechoCita(tipo: tipo, fecha: DateTime(2026, 10, 1, 10), detalle: d);

    expect(
      h('cobrada', {'en_caja': true, 'metodo': 'efectivo'}).titulo,
      'Cobrada en caja · Efectivo',
    );
    expect(h('cobrada', {'en_caja': false}).titulo, 'Pagada en línea');
    expect(
      h('cancelada', {'por': 'cliente'}).titulo,
      'Cancelada por el cliente',
    );
    expect(
      h('asistencia_corregida', {'estado': 'ausente'}).titulo,
      'Asistencia corregida: no asistió',
    );
    expect(
      h('metodo_corregido', {
        'de': 'efectivo',
        'a': 'manual',
        'motivo': 'Se equivocó',
      }).detalleEn('MXN', (m, _) => '$m'),
      'De Efectivo a Tarjeta (terminal) · Motivo: Se equivocó',
    );
  });
}
