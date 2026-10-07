import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/data/agenda_models.dart';
import 'package:agendauno/features/agenda/data/agenda_repository.dart';
import 'package:agendauno/features/agenda/presentation/abrir_sesion.dart';
import 'package:agendauno/features/agenda/presentation/agenda_screen.dart';
import 'package:agendauno/features/agenda/presentation/pase_lista_screen.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';

/// La agenda del equipo abre lo que corresponde al TIPO de cada sesión (ADR 0104,
/// informe M11): una cita, su hoja; una clase, su pase de lista. Antes una cita
/// abría el pase de lista y una clase en la vista de citas no se podía tocar.
/// Datos sintéticos.

final _hoy = DateTime.now();
String _a(int h) =>
    DateTime(_hoy.year, _hoy.month, _hoy.day, h).toUtc().toIso8601String();

Map<String, dynamic> _cita({Map<String, dynamic>? titular}) => {
  'id': 'cita-1',
  'tipo': 'cita',
  'oferta': 'Corte',
  'oferta_id': 'o1',
  'instructor': 'Beto Ramírez',
  'instructor_id': 'p1',
  'inicia_en': _a(10),
  'termina_en': _a(11),
  'capacidad': 1,
  'ocupados': 1,
  'en_espera': 0,
  'estado': 'programada',
  'clase': null,
  'ocupacion': null,
  'cita':
      titular ??
      {
        'reserva_id': 'r1',
        'cliente': 'Ana López',
        'estado': 'confirmada',
        'asistencia': null,
        'orden_id': null,
        'por_cobrar': false,
        'estado_atencion': 'confirmada',
        'estado_pago': null,
      },
};

Map<String, dynamic> _clase() => {
  'id': 'clase-1',
  'tipo': 'clase',
  'oferta': 'Pole Nivel 1',
  'oferta_id': 'o2',
  'instructor': 'Beto Ramírez',
  'instructor_id': 'p1',
  'inicia_en': _a(13),
  'termina_en': _a(14),
  'capacidad': 10,
  'ocupados': 4,
  'en_espera': 0,
  'estado': 'programada',
  'clase': {
    'capacidad': 10,
    'ocupados': 4,
    'libres': 6,
    'en_espera': 0,
    'lugares': 0,
    'de_pago': false,
    'precio_minor': null,
  },
  'ocupacion': {'ocupados': 4, 'capacidad': 10, 'porcentaje': 40},
  'cita': null,
};

class _Agenda extends AgendaController {
  @override
  Future<AgendaDia> build() async => AgendaDia(
    sesiones: [
      SesionAgenda.desdeJson(_cita()),
      SesionAgenda.desdeJson(_clase()),
    ],
    profesionales: const [Profesional(id: 'p1', nombre: 'Beto Ramírez')],
  );
}

/// El pase de lista pide su lista: vacía.
class _ApiVacia implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async => ResponseBody.fromString(
    jsonEncode({'data': <Object>[]}),
    200,
    headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    },
  );

  @override
  void close({bool force = false}) {}
}

Sesion _recepcion(Modalidad modalidad) => Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Recepción',
  rol: 'propietario',
  permisos: const ['*'],
  modalidad: modalidad,
);

Future<void> _montar(WidgetTester tester, Modalidad modalidad) async {
  // Alto para que el día entero quepa sin desplazarse.
  tester.view.physicalSize = const Size(420, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(_recepcion(modalidad)),
        agendaProvider.overrideWith(_Agenda.new),
        agendaRepositoryProvider.overrideWithValue(
          AgendaRepository(Dio()..httpClientAdapter = _ApiVacia(), 'demo'),
        ),
      ],
      child: const MaterialApp(home: AgendaScreen()),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('en la vista de clases, una cita abre su hoja y no el pase de '
      'lista', (tester) async {
    await _montar(tester, Modalidad.clases);

    await tester.tap(find.text('Ana López'));
    await tester.pumpAndSettle();

    expect(find.byType(PaseListaScreen), findsNothing);
    expect(find.text('Cancelar cita'), findsOneWidget);
    expect(find.text('Llegó'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('en la vista de clases, una clase abre su pase de lista con la '
      'ocupación del servidor', (tester) async {
    await _montar(tester, Modalidad.clases);

    // El anillo muestra lo que dice `ocupacion` (40 %).
    expect(
      find.byWidgetPredicate(
        (w) => w is CircularProgressIndicator && w.value == 0.4,
      ),
      findsOneWidget,
    );
    await tester.tap(find.text('Pole Nivel 1'));
    await tester.pumpAndSettle();

    expect(find.byType(PaseListaScreen), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
    'en la vista de citas, una clase se puede tocar y abre su pase de '
    'lista',
    (tester) async {
      await _montar(tester, Modalidad.citas);

      await tester.tap(find.text('Pole Nivel 1'));
      await tester.pumpAndSettle();

      expect(find.byType(PaseListaScreen), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
    },
  );

  testWidgets('en la vista de citas, la cita abre su hoja con el estado del '
      'servidor', (tester) async {
    await _montar(tester, Modalidad.citas);

    await tester.tap(find.text('Ana López'));
    await tester.pumpAndSettle();

    expect(find.byType(PaseListaScreen), findsNothing);
    expect(find.text('Cancelar cita'), findsOneWidget);
    expect(find.text('Confirmada'), findsWidgets);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets(
    'no se abre nada sin titular ni, en una clase, sin ver reservas',
    (tester) async {
      late BuildContext contexto;
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) {
              contexto = context;
              return const SizedBox();
            },
          ),
        ),
      );
      final sinTitular = SesionAgenda.desdeJson({..._cita(), 'cita': null});
      final cita = SesionAgenda.desdeJson(_cita());
      final clase = SesionAgenda.desdeJson(_clase());
      final cancelada = SesionAgenda.desdeJson({
        ..._clase(),
        'estado': 'cancelada',
      });
      const sinPermiso = Sesion(
        slug: 'demo',
        bearer: 't',
        nombre: 'X',
        rol: 'x',
      );
      final todo = _recepcion(Modalidad.clases);

      expect(alTocarSesion(contexto, sinTitular, todo), isNull);
      expect(alTocarSesion(contexto, cita, todo), isNotNull);
      expect(alTocarSesion(contexto, clase, todo), isNotNull);
      expect(alTocarSesion(contexto, clase, sinPermiso), isNull);
      expect(alTocarSesion(contexto, cancelada, todo), isNull);
    },
  );
}
