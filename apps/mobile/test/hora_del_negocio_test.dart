import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/calendario/calendario.dart';
import 'package:agendauno/core/calendario/calendario_vistas.dart';
import 'package:agendauno/core/formato.dart';
import 'package:agendauno/core/hora_negocio.dart';
import 'package:agendauno/core/network/auth_token.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/features/agenda/application/agenda_controller.dart';
import 'package:agendauno/features/agenda/presentation/agenda_screen.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/corte_planes.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/eventos_cuenta.dart';
import 'package:agendauno/features/inicio/data/inicio_repository.dart';
import 'package:agendauno/features/inicio/data/resumen_hoy.dart';
import 'package:agendauno/features/inicio/presentation/equipo_screen.dart';
import 'package:agendauno/features/instructor/application/mis_clases_controller.dart';
import 'package:agendauno/features/instructor/presentation/instructor_screen.dart';

/// Los días y las horas de la app son los del negocio o de su sucursal, no los del
/// teléfono (ADR 0166): el Inicio del equipo pide el día del negocio, la agenda y
/// Mis clases arrancan en él (también en un día sin nada), y el portal del cliente
/// muestra sus fechas en la hora del negocio. El desfase se pone al día con /yo
/// (horario de verano). Datos sintéticos.
///
/// Lo que depende del reloj no depende de la zona de la máquina: el negocio se
/// pone 13 horas antes o después del teléfono, así hoy allá es otro día.

/// Un desfase del negocio con el que hoy allá es OTRO día que en el teléfono: 13
/// horas antes (si en el teléfono aún no es mediodía) o 13 después.
int _desfaseDeOtroDia(DateTime ahora) {
  final telefono = ahora.timeZoneOffset.inMinutes;
  return ahora.hour < 12 ? telefono - 13 * 60 : telefono + 13 * 60;
}

Sesion _sesion({
  int? desfase,
  String rol = 'propietario',
  List<String> permisos = const ['*'],
  Modalidad modalidad = Modalidad.clases,
}) => Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Ana',
  rol: rol,
  roles: [rol],
  permisos: permisos,
  modalidad: modalidad,
  desfaseNegocioMin: desfase,
);

/// API falso: anota cada petición y responde lo que diga [responder].
class _Api implements HttpClientAdapter {
  _Api(this.responder);

  final Map<String, dynamic> Function(RequestOptions o) responder;
  final peticiones = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    peticiones.add(options);
    return ResponseBody.fromString(
      jsonEncode(responder(options)),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

(ProviderContainer, _Api) _contenedor(
  Sesion sesion,
  Map<String, dynamic> Function(RequestOptions o) responder,
) {
  final c = ProviderContainer(
    overrides: [
      almacenSesionProvider.overrideWithValue(AlmacenSesion()),
      tokenInicialProvider.overrideWithValue(sesion.bearer),
      sesionInicialProvider.overrideWithValue(sesion),
    ],
  );
  final api = _Api(responder);
  c.read(dioProvider).httpClientAdapter = api;
  return (c, api);
}

void main() {
  group('HoraNegocio', () {
    test('un instante, en la hora de pared de la zona con ese desfase', () {
      final instante = DateTime.utc(2030, 1, 10, 3, 30);

      expect(
        HoraNegocio.enDesfase(instante, -360),
        DateTime(2030, 1, 9, 21, 30),
      );
      expect(HoraNegocio.enDesfase(instante, 330), DateTime(2030, 1, 10, 9, 0));
      // Sin desfase (API anterior), la del teléfono.
      expect(HoraNegocio.enDesfase(instante, null), instante.toLocal());
    });

    test('un texto sin zona ya es la hora local: se toma tal cual', () {
      expect(
        HoraNegocio.pared('2030-01-09T21:30', desfaseMin: 120),
        DateTime(2030, 1, 9, 21, 30),
      );
      expect(
        HoraNegocio.pared('2030-01-10T03:30:00+00:00', desfaseMin: -360),
        DateTime(2030, 1, 9, 21, 30),
      );
      expect(HoraNegocio.pared(null), isNull);
      expect(HoraNegocio.pared('mañana'), isNull);
    });
  });

  group('Inicio del equipo', () {
    test('pide el día de hoy del negocio, no el del teléfono', () async {
      final ahora = DateTime.now();
      final sesion = _sesion(desfase: _desfaseDeOtroDia(ahora));
      final negocio = sesion.hoyEnNegocio(ahora);
      expect(Calendario.mismoDia(negocio, ahora), isFalse);
      final (c, api) = _contenedor(
        sesion,
        (_) => {
          'data': {'fecha': Formato.iso(negocio), 'agenda': null},
        },
      );
      addTearDown(c.dispose);
      final escucha = c.listen(resumenHoyProvider, (_, _) {});
      addTearDown(escucha.close);

      await c.read(resumenHoyProvider.future);

      final pedido = api.peticiones.single;
      expect(pedido.path, endsWith('/inicio/hoy'));
      expect(pedido.queryParameters['fecha'], Formato.iso(negocio));
    });

    test('sin el desfase (API anterior) no manda el día: el servidor da el de '
        'hoy en su zona', () async {
      final (c, api) = _contenedor(
        _sesion(),
        (_) => {
          'data': {'fecha': '2026-10-02', 'agenda': null},
        },
      );
      addTearDown(c.dispose);
      final escucha = c.listen(resumenHoyProvider, (_, _) {});
      addTearDown(escucha.close);

      await c.read(resumenHoyProvider.future);

      expect(
        api.peticiones.single.queryParameters.containsKey('fecha'),
        isFalse,
      );
    });

    test('el mes va del día 1 a hoy en el negocio', () async {
      final ahora = DateTime.now();
      final sesion = _sesion(desfase: _desfaseDeOtroDia(ahora));
      final negocio = sesion.hoyEnNegocio(ahora);
      final (c, api) = _contenedor(
        sesion,
        (_) => {'data': <String, dynamic>{}},
      );
      addTearDown(c.dispose);
      final escucha = c.listen(resumenMesProvider, (_, _) {});
      addTearDown(escucha.close);

      await c.read(resumenMesProvider.future);

      expect(api.peticiones, hasLength(2));
      for (final p in api.peticiones) {
        expect(p.queryParameters, {
          'desde': Formato.iso(DateTime(negocio.year, negocio.month)),
          'hasta': Formato.iso(negocio),
        });
      }
    });

    test('la siguiente sesión, en la hora de su sucursal y con su etiqueta '
        'si el teléfono está en otra zona', () {
      final json = {
        'id': 's1',
        'tipo': 'clase',
        'oferta': 'Pole Nivel 1',
        'sucursal': 'Roma Norte',
        'inicia_en': '2026-10-02T05:30:00+00:00',
        'inicia_local': '2026-10-01T23:30:00',
        'utc_offset_min': -360,
        'momento': 'proxima',
      };
      final s = SesionHoy.desdeJson(json);

      expect(s.iniciaLocal, DateTime(2026, 10, 1, 23, 30));
      expect(s.etiquetaHora(-360), isNull);
      expect(s.etiquetaHora(-300), 'Hora de Roma Norte');
      // Sin desfase se deduce de la hora local (como en la agenda).
      expect(
        SesionHoy.desdeJson({...json}..remove('utc_offset_min')).desfaseMin,
        -360,
      );
      // Sin hora local se calcula con el desfase.
      expect(
        SesionHoy.desdeJson({...json}..remove('inicia_local')).iniciaLocal,
        DateTime(2026, 10, 1, 23, 30),
      );
    });

    test('los espacios libres, desde la hora de la sede', () {
      final l = LibreHoy.desdeJson({
        'profesional': 'Caro',
        'sucursal': 'Roma Norte',
        'huecos': 3,
        'siguiente': '2026-10-02T16:00:00+00:00',
        'siguiente_local': '2026-10-02T10:00:00',
      });

      expect(Formato.hora(l.siguiente!), '10:00');
    });

    testWidgets('el Inicio dice que la hora es la de la sucursal y los huecos '
        'salen en la hora de la sede', (tester) async {
      tester.view.physicalSize = const Size(390, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      // Una sucursal dos horas detrás del teléfono.
      final inicia = DateTime.now().add(const Duration(hours: 1));
      final desfase = HoraNegocio.desfaseTelefono(inicia) - 120;
      final local = HoraNegocio.enDesfase(inicia, desfase);
      String pared(DateTime d) => '${Formato.iso(d)}T${Formato.hora(d)}:00';
      final resumen = ResumenHoy.desdeJson({
        'fecha': Formato.iso(local),
        'agenda': {
          'totales': {'sesiones': 1},
          'sesiones': [
            {
              'id': 'c1',
              'tipo': 'cita',
              'oferta': 'Corte',
              'instructor': 'Caro',
              'sucursal': 'Roma Norte',
              'cliente': 'Dana',
              'inicia_en': inicia.toUtc().toIso8601String(),
              'inicia_local': pared(local),
              'utc_offset_min': desfase,
              'momento': 'proxima',
            },
          ],
        },
        'libres': [
          {
            'profesional': 'Caro',
            'sucursal': 'Roma Norte',
            'huecos': 2,
            'siguiente': '2026-10-02T16:00:00+00:00',
            'siguiente_local': '2026-10-02T10:00:00',
          },
        ],
      });

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            sesionInicialProvider.overrideWithValue(
              _sesion(
                desfase: desfase,
                permisos: const ['agenda.ver'],
                modalidad: Modalidad.citas,
              ),
            ),
            resumenHoyProvider.overrideWith((ref) async => resumen),
            climaNegocioProvider.overrideWith((ref) async => null),
          ],
          child: const MaterialApp(home: EquipoScreen()),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Dana'), findsOneWidget);
      expect(
        find.text('${Formato.hora(local)} · Corte · Caro · Roma Norte'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('hora-de-la-sucursal')), findsOneWidget);
      expect(find.text('Hora de Roma Norte'), findsOneWidget);
      expect(find.text('desde las 10:00'), findsOneWidget);
    });
  });

  group('agenda en un día sin sesiones', () {
    test('sin sesiones con desfase no inventa el día de la sucursal', () {
      const vacia = AgendaDia(sesiones: [], profesionales: []);

      expect(vacia.hoyEnSucursal(DateTime.now()), isNull);
    });

    testWidgets(
      '«hoy» es el del negocio: en su día no ofrece «Hoy» y «Hoy» vuelve a él',
      (tester) async {
        tester.view.physicalSize = const Size(600, 1000);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        final ahora = DateTime.now();
        final sesion = _sesion(desfase: _desfaseDeOtroDia(ahora));
        final negocio = sesion.hoyEnNegocio(ahora);
        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              sesionInicialProvider.overrideWithValue(sesion),
              agendaProvider.overrideWith(_AgendaVacia.new),
            ],
            child: const MaterialApp(home: AgendaScreen()),
          ),
        );
        await tester.pumpAndSettle();
        final c = ProviderScope.containerOf(
          tester.element(find.byType(AgendaScreen)),
        );

        // Abre en el día del negocio y, como ya es hoy allá, no ofrece «Hoy».
        expect(c.read(fechaAgendaProvider), negocio);
        expect(find.widgetWithText(TextButton, 'Hoy'), findsNothing);

        // En otra semana, «Hoy» regresa al día del negocio (no al del teléfono).
        await tester.tap(find.byTooltip('Semana siguiente'));
        await tester.pumpAndSettle();
        expect(find.widgetWithText(TextButton, 'Hoy'), findsOneWidget);
        await tester.tap(find.widgetWithText(TextButton, 'Hoy'));
        await tester.pumpAndSettle();
        expect(c.read(fechaAgendaProvider), negocio);
        expect(find.widgetWithText(TextButton, 'Hoy'), findsNothing);
      },
    );
  });

  group('Mis clases del instructor', () {
    test('el rango arranca en el día del negocio', () {
      final ahora = DateTime.now();
      final sesion = _sesion(
        desfase: _desfaseDeOtroDia(ahora),
        rol: 'instructor',
      );
      final c = ProviderContainer(
        overrides: [sesionInicialProvider.overrideWithValue(sesion)],
      );
      addTearDown(c.dispose);
      final negocio = sesion.hoyEnNegocio(ahora);

      final rango = c.read(rangoMisClasesProvider);

      expect(Calendario.mismoDia(negocio, ahora), isFalse);
      expect(rango.desde, negocio);
      expect(rango.hasta, Calendario.mas(negocio, 30));
    });

    testWidgets('su calendario marca hoy el día del negocio', (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final ahora = DateTime.now();
      final sesion = _sesion(
        desfase: _desfaseDeOtroDia(ahora),
        rol: 'instructor',
      );
      final negocio = sesion.hoyEnNegocio(ahora);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            sesionInicialProvider.overrideWithValue(sesion),
            proximasMisClasesProvider.overrideWith((ref) async => const []),
            misClasesProvider.overrideWith((ref) async => const []),
            climaEquipoProvider.overrideWith((ref) async => null),
          ],
          child: const MaterialApp(home: InstructorScreen()),
        ),
      );
      await tester.pumpAndSettle();

      final calendario = find.widgetWithText(FilledButton, 'Mi calendario');
      await tester.ensureVisible(calendario);
      await tester.pumpAndSettle();
      await tester.tap(calendario);
      await tester.pumpAndSettle();
      expect(
        tester.widget<CalendarioVistas>(find.byType(CalendarioVistas)).hoy,
        negocio,
      );
      // La vista Día abre en hoy del negocio.
      await tester.tap(find.text('Día'));
      await tester.pumpAndSettle();
      expect(
        find.text(Calendario.etiqueta(VistaCal.dia, negocio)),
        findsOneWidget,
      );
    });
  });

  group('portal del cliente', () {
    test(
      'Formato.fechaHora: un instante en la hora del negocio; una hora local '
      'tal cual',
      () {
        expect(
          Formato.fechaHora('2030-01-10T03:30:00+00:00', desfaseMin: -360),
          'mié 9 ene · 21:30',
        );
        expect(
          Formato.fechaHora('2030-01-09T21:30', desfaseMin: 120),
          'mié 9 ene · 21:30',
        );
        expect(Formato.fechaHora(null, desfaseMin: -360), '—');
      },
    );

    test('el calendario agrupa por el día del negocio', () {
      final cuenta = MiCuenta(
        derechos: const [],
        reservas: [
          ReservaMiembro.desdeJson({
            'id': 'r1',
            'tipo': 'clase',
            'estado': 'confirmada',
            'oferta': 'Pole Nivel 1',
            'inicia_en': '2030-01-10T03:30:00+00:00',
            'termina_en': '2030-01-10T04:30:00+00:00',
          }),
        ],
        clases: [
          ClaseMiembro.desdeJson({
            'id': 's2',
            'oferta': 'Flexibilidad',
            'inicia_en': '2030-01-10T15:00:00+00:00',
            'capacidad': 8,
            'ocupados': 2,
          }),
        ],
      );
      List<String> delDia(int desfase, int dia) => Calendario.delDia(
        eventosDeCuenta(cuenta, desfaseMin: desfase),
        DateTime(2030, 1, dia),
      ).map((e) => e.id).toList();

      // Seis horas detrás de UTC: la reserva es el 9 a las 21:30.
      expect(delDia(-360, 9), ['r1']);
      expect(delDia(-360, 10), ['s2']);
      final r1 = eventosDeCuenta(
        cuenta,
        desfaseMin: -360,
      ).firstWhere((e) => e.id == 'r1');
      expect(r1.inicio, DateTime(2030, 1, 9, 21, 30));
      expect(r1.fin, DateTime(2030, 1, 9, 22, 30));
      // Diez horas delante: las dos un día después.
      expect(delDia(600, 10), ['r1']);
      expect(delDia(600, 11), ['s2']);
    });

    test('lo que se lee ya listo para mostrar va en la hora del negocio', () {
      final orden = OrdenPorPagar.desdeJson({
        'id': 'o1',
        'concepto': 'Corte',
        'total_minor': 25000,
        'estado': 'pagada',
        'pagada_en': '2030-01-10T03:30:00+00:00',
        'sesion': {
          'profesional': 'Caro',
          'inicia_en': '2030-01-10T03:30:00+00:00',
          'sucursal': 'Roma Norte',
        },
      }, desfaseMin: -360);
      expect(orden.detalle, 'Con Caro · mié 9 ene · 21:30 · Roma Norte');
      expect(orden.fecha, DateTime(2030, 1, 9, 21, 30));

      final plan = PlanCorte.desdeJson({
        'id': 'a1',
        'derecho_id': 'd1',
        'unidades': <String, dynamic>{},
        'usos': [
          {'clase': 'Nivel 1', 'inicia_en': '2030-01-10T03:30:00Z'},
        ],
      }, desfaseMin: -360);
      expect(plan.usos.single.iniciaEn, DateTime(2030, 1, 9, 21, 30));

      final antes = AntesDeReservar.desdeJson({
        'resultado': 'reserva',
        'unidades': 1000,
        'cancelacion': {
          'limite': '2030-01-10T08:00:00Z',
          'penaliza_tarde': true,
          'cancela_tarde': true,
        },
      });
      expect(
        antes.cancelacionTexto(DateTime.utc(2030, 1, 9), desfaseMin: -360),
        startsWith('Cancela sin costo hasta el jue 10 ene · 02:00.'),
      );

      // Otras fechas de la clase al reprogramar: con la hora de su sede.
      final opciones = OpcionesReprogramar.desdeJson({
        'puede': true,
        'tipo': 'clase',
        'restantes': 1,
        'sesiones': [
          {
            'id': 's9',
            'inicia_en': '2030-01-10T03:30:00+00:00',
            'inicia_local': '2030-01-09T21:30',
          },
        ],
      });
      expect(opciones.sesiones.single.iniciaLocal, '2030-01-09T21:30');
    });

    test('cada reserva y clase, en la hora de su sede si el API la manda', () {
      // El negocio va seis horas detrás de UTC; la sede de Cancún, cinco.
      final cuenta = MiCuenta(
        derechos: const [],
        reservas: [
          ReservaMiembro.desdeJson({
            'id': 'r1',
            'tipo': 'clase',
            'estado': 'confirmada',
            'oferta': 'Pole Nivel 1',
            'inicia_en': '2030-01-10T03:30:00+00:00',
            'termina_en': '2030-01-10T04:30:00+00:00',
            'utc_offset_min': -300,
          }),
        ],
        clases: [
          ClaseMiembro.desdeJson({
            'id': 's2',
            'oferta': 'Flexibilidad',
            'inicia_en': '2030-01-10T15:00:00+00:00',
            'utc_offset_min': -300,
          }),
          // Un API anterior no manda el de la sede: el del negocio.
          ClaseMiembro.desdeJson({
            'id': 's3',
            'oferta': 'Aéreo',
            'inicia_en': '2030-01-10T16:00:00+00:00',
          }),
        ],
      );
      final eventos = {
        for (final e in eventosDeCuenta(cuenta, desfaseMin: -360)) e.id: e,
      };

      expect(eventos['r1']!.inicio, DateTime(2030, 1, 9, 22, 30));
      expect(eventos['r1']!.fin, DateTime(2030, 1, 9, 23, 30));
      expect(eventos['s2']!.inicio, DateTime(2030, 1, 10, 10));
      expect(eventos['s3']!.inicio, DateTime(2030, 1, 10, 10));

      final orden = OrdenPorPagar.desdeJson({
        'id': 'o1',
        'concepto': 'Corte',
        'total_minor': 25000,
        'sesion': {
          'inicia_en': '2030-01-10T03:30:00+00:00',
          'utc_offset_min': -300,
          'sucursal': 'Cancún',
        },
      }, desfaseMin: -360);
      expect(orden.detalle, 'mié 9 ene · 22:30 · Cancún');

      final historial = ItemHistorial.desdeJson({
        'id': 'h1',
        'estado': 'asistio',
        'inicia_en': '2030-01-10T03:30:00+00:00',
        'utc_offset_min': -300,
      });
      final movimiento = MovimientoCredito.desdeJson({
        'id': 'm1',
        'concepto': 'Reserva',
        'unidades': -1000,
        'saldo_posterior': 0,
        'clase': {
          'nombre': 'Nivel 1',
          'inicia_en': '2030-01-10T03:30:00+00:00',
          'utc_offset_min': -300,
        },
      });
      final plan = PlanCorte.desdeJson({
        'id': 'a1',
        'derecho_id': 'd1',
        'unidades': <String, dynamic>{},
        'usos': [
          {
            'clase': 'Nivel 1',
            'inicia_en': '2030-01-10T03:30:00Z',
            'utc_offset_min': -300,
          },
        ],
      }, desfaseMin: -360);
      expect(plan.usos.single.iniciaEn, DateTime(2030, 1, 9, 22, 30));
      expect(historial.desfaseMin, -300);
      expect(movimiento.claseDesfaseMin, -300);
      expect(
        ResenaPendiente.desdeJson({
          'reserva_id': 'r1',
          'utc_offset_min': -300,
        }).desfaseMin,
        -300,
      );
    });

    test('el calendario de Reservas arranca en el día del negocio', () {
      final ahora = DateTime.now();
      final sesion = _sesion(desfase: _desfaseDeOtroDia(ahora), rol: 'miembro');
      final c = ProviderContainer(
        overrides: [sesionInicialProvider.overrideWithValue(sesion)],
      );
      addTearDown(c.dispose);
      final negocio = sesion.hoyEnNegocio(ahora);

      final filtro = c.read(filtroAgendaProvider);

      expect(filtro.desde, negocio);
      expect(filtro.hasta, Calendario.mas(negocio, 30));
    });
  });

  group('el desfase del negocio se pone al día', () {
    test('con /yo (al volver a la app) y al cambiar de rol', () async {
      final sesion = Sesion(
        slug: 'demo',
        bearer: 'token-ana',
        nombre: 'Ana',
        rol: 'admin',
        roles: const ['admin', 'instructor'],
        desfaseNegocioMin: -300,
      );
      FlutterSecureStorage.setMockInitialValues({
        'agendauno.sesion': jsonEncode(sesion.aJson()),
      });
      var desfase = -360;
      final (c, api) = _contenedor(sesion, (o) {
        final usuario = {
          'nombre': 'Ana',
          'rol': o.path.endsWith('/rol-activo') ? 'instructor' : 'admin',
          'roles': ['admin', 'instructor'],
        };
        return {
          'data': {
            'usuario': usuario,
            'estudio': {'nombre': 'Demo', 'zona_desfase_min': desfase},
          },
        };
      });
      addTearDown(c.dispose);

      // Cambió el horario de verano: /yo trae el desfase de hoy.
      await c.read(sesionProvider.notifier).revisar();
      expect(c.read(sesionProvider)!.desfaseNegocioMin, -360);
      final guardada = await AlmacenSesion().leer();
      expect(Sesion.desdeAlmacen(guardada!)!.desfaseNegocioMin, -360);

      // Al cambiar de rol también (la respuesta trae el negocio).
      desfase = -300;
      await c.read(sesionProvider.notifier).cambiarRol('instructor');
      expect(api.peticiones.last.path, endsWith('/yo/rol-activo'));
      expect(c.read(sesionProvider)!.rol, 'instructor');
      expect(c.read(sesionProvider)!.desfaseNegocioMin, -300);
      // Lo que no trae el negocio no lo borra.
      expect(
        c.read(sesionProvider)!.conUsuario(const {}).desfaseNegocioMin,
        -300,
      );
    });
  });
}

class _AgendaVacia extends AgendaController {
  @override
  Future<AgendaDia> build() async =>
      const AgendaDia(sesiones: [], profesionales: []);
}
