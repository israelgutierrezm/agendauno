import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/presentation/agendar_cita_sheet.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_screen.dart';
import 'package:agendauno/features/inicio/data/inicio_repository.dart';
import 'package:agendauno/features/inicio/data/resumen_hoy.dart';
import 'package:agendauno/features/inicio/presentation/equipo_screen.dart';

/// La app al día con la web en la operación por tipo de negocio (ADR 0091): la
/// cuenta del cliente muestra solo lo que le sirve, los servicios de su bono no se
/// pagan al agendar y el Inicio del equipo de citas dice dónde hay espacio.
/// Datos sintéticos.

/// Responde como el API según la ruta; lo demás, 404.
class _Api implements HttpClientAdapter {
  _Api(this.rutas);

  final Map<String, Object> rutas;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final ruta = rutas.keys.where(options.path.endsWith).firstOrNull;
    return ResponseBody.fromString(
      jsonEncode(ruta == null ? {'message': 'No'} : {'data': rutas[ruta]}),
      ruta == null ? 404 : 200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

class _CuentaFalsa extends CuentaController {
  _CuentaFalsa(this.cuenta);

  final MiCuenta cuenta;

  @override
  Future<MiCuenta> build() async => cuenta;
}

String _iso(DateTime d) => d.toUtc().toIso8601String();

Future<void> _montar(
  WidgetTester tester,
  Widget pantalla,
  List overrides, {
  double alto = 1400,
}) async {
  tester.view.physicalSize = Size(390, alto);
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

  test('la cuenta trae qué partes le sirven, su asistencia, el vencimiento '
      'y cómo llegar', () async {
    final repo = CuentaRepository(
      Dio()
        ..httpClientAdapter = _Api({
          '/mi/perfil': {
            'derechos': [
              {
                'id': 'd1',
                'ilimitado': false,
                'disponible': 3000,
                'vence': '2026-11-14',
              },
            ],
            'reservas': [
              {
                'id': 'r1',
                'estado': 'confirmada',
                'tipo': 'cita',
                'mapa_url': 'https://maps.app.goo.gl/centro',
              },
            ],
            'portal': {'creditos': true, 'pase': false, 'expediente': true},
            'asistencias_30_dias': 4,
          },
          '/mi/waivers': <Object>[],
        }),
      'demo',
    );

    final cuenta = await repo.cargar(conClases: false);

    expect(cuenta.portal!.creditos, isTrue);
    expect(cuenta.portal!.pase, isFalse);
    expect(cuenta.portal!.expediente, isTrue);
    expect(cuenta.asistencias30Dias, 4);
    expect(cuenta.derechos.single.vence, '2026-11-14');
    expect(cuenta.reservas.single.mapaUrl, 'https://maps.app.goo.gl/centro');

    // Un API anterior no lo dice: se muestra todo, como antes.
    expect(PortalCliente.desdeJson(null), isNull);
  });

  testWidgets('citas: sus citas primero, «Cómo llegar» y sin tarjetas que no '
      'usa el negocio', (tester) async {
    final cita = DateTime(manana.year, manana.month, manana.day, 10);
    await _montar(tester, const CuentaScreen(), [
      sesionInicialProvider.overrideWithValue(
        const Sesion(
          slug: 'demo',
          bearer: 't',
          nombre: 'Ana',
          rol: 'miembro',
          modalidad: Modalidad.citas,
          terminologia: Terminologia(sesion: 'Cita', sesionesPlural: 'Citas'),
        ),
      ),
      cuentaProvider.overrideWith(
        () => _CuentaFalsa(
          MiCuenta(
            derechos: const [],
            reservas: [
              ReservaMiembro(
                id: 'r1',
                estado: 'confirmada',
                tipo: 'cita',
                oferta: 'Corte de cabello',
                sucursal: 'Centro',
                iniciaEn: _iso(cita),
                mapaUrl: 'https://maps.app.goo.gl/centro',
              ),
            ],
            clases: const [],
            portal: const PortalCliente(
              creditos: false,
              pase: false,
              expediente: false,
            ),
          ),
        ),
      ),
      climaProvider.overrideWith((ref) async => null),
      cortePlanesProvider.overrideWith((ref) async => const []),
    ]);

    expect(find.text('Cómo llegar'), findsOneWidget);
    expect(find.text('Mis citas'), findsOneWidget);
    expect(find.text('Volver a agendar'), findsOneWidget);
    // «Cambiar o cancelar» en la tarjeta grande y en «Mis citas».
    expect(find.text('Cambiar o cancelar'), findsNWidgets(2));
    expect(find.text('Mi bono o membresía'), findsNothing);
    expect(find.text('Sin paquete activo'), findsNothing);
    expect(find.text('Pase de entrada'), findsNothing);
    expect(find.text('Documentos del negocio'), findsNothing);
    expect(find.text('Mi asistencia'), findsNothing);
    // Sus citas van antes que volver a agendar.
    expect(
      tester.getTopLeft(find.text('Mis citas')).dy <=
          tester.getTopLeft(find.text('Volver a agendar')).dy,
      isTrue,
    );
  });

  testWidgets('clases: su plan con vencimiento y su asistencia de 30 días', (
    tester,
  ) async {
    await _montar(tester, const CuentaScreen(), [
      sesionInicialProvider.overrideWithValue(
        const Sesion(slug: 'demo', bearer: 't', nombre: 'Vale', rol: 'miembro'),
      ),
      cuentaProvider.overrideWith(
        () => _CuentaFalsa(
          const MiCuenta(
            derechos: [
              DerechoMiembro(
                ilimitado: false,
                id: 'd1',
                disponible: 6000,
                vence: '2026-11-14',
              ),
            ],
            reservas: [],
            clases: [],
            portal: PortalCliente(
              creditos: true,
              pase: false,
              expediente: false,
            ),
            asistencias30Dias: 3,
          ),
        ),
      ),
      climaProvider.overrideWith((ref) async => null),
      cortePlanesProvider.overrideWith((ref) async => const []),
    ]);

    expect(find.text('Mis créditos'), findsOneWidget);
    expect(find.text('6 créditos · vence el 14 nov'), findsOneWidget);
    expect(find.text('Mi asistencia'), findsOneWidget);
    expect(find.text('3 clases en 30 días'), findsOneWidget);
    expect(find.text('Pase de entrada'), findsNothing);
  });

  testWidgets(
    'Mis créditos suma solo lo vigente: un paquete vencido no cuenta',
    (tester) async {
      await _montar(tester, const CuentaScreen(), [
        sesionInicialProvider.overrideWithValue(
          const Sesion(
            slug: 'demo',
            bearer: 't',
            nombre: 'Vale',
            rol: 'miembro',
          ),
        ),
        cuentaProvider.overrideWith(
          () => _CuentaFalsa(
            const MiCuenta(
              derechos: [
                DerechoMiembro(
                  ilimitado: false,
                  id: 'd1',
                  disponible: 6000,
                  vence: '2099-11-14',
                ),
                DerechoMiembro(
                  ilimitado: false,
                  id: 'vencido',
                  disponible: 4000,
                  vence: '2020-01-31',
                ),
              ],
              reservas: [],
              clases: [],
            ),
          ),
        ),
        climaProvider.overrideWith((ref) async => null),
        cortePlanesProvider.overrideWith((ref) async => const []),
      ]);

      expect(find.text('6 créditos · vence el 14 nov'), findsOneWidget);
      expect(find.textContaining('10 créditos'), findsNothing);
    },
  );

  testWidgets('al agendar, el servicio de su bono dice que no se paga', (
    tester,
  ) async {
    final repo = CuentaRepository(
      Dio()
        ..httpClientAdapter = _Api({
          '/mi/citas/opciones': {
            'servicios': [
              {'id': 'corte', 'nombre': 'Corte', 'duracion_minutos': 30},
              {
                'id': 'barba',
                'nombre': 'Barba',
                'duracion_minutos': 30,
                'con_plan': true,
              },
            ],
            'sucursales': [
              {'id': 's1', 'nombre': 'Centro'},
            ],
            'instructores': [
              {'id': 'p1', 'nombre': 'Ana'},
            ],
          },
        }),
      'demo',
    );
    await _montar(tester, const Scaffold(body: AgendarCitaSheet()), [
      sesionInicialProvider.overrideWithValue(
        const Sesion(slug: 'demo', bearer: 't', nombre: 'Vale', rol: 'miembro'),
      ),
      cuentaRepositoryProvider.overrideWithValue(repo),
    ]);

    expect(find.byKey(const Key('pago-con-bono')), findsNothing);
    await tester.tap(find.byType(DropdownButtonFormField<OpcionCita>).first);
    await tester.pumpAndSettle();
    expect(find.text('Corte'), findsWidgets);
    await tester.tap(find.text('Barba · Con tu bono').last);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('pago-con-bono')), findsOneWidget);
    expect(
      find.text(
        'Se descuenta una sesión de tu bono o membresía; no pagas al agendar.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('el Inicio del equipo de citas: quién viene, por atender y '
      'espacios libres', (tester) async {
    final hoy = DateTime.now();
    DateTime a(int h, [int m = 0]) =>
        DateTime(hoy.year, hoy.month, hoy.day, h, m);
    await _montar(tester, const EquipoScreen(), [
      sesionInicialProvider.overrideWithValue(
        const Sesion(
          slug: 'demo',
          bearer: 't',
          nombre: 'Marco',
          rol: 'propietario',
          permisos: ['*'],
          modalidad: Modalidad.citas,
          terminologia: Terminologia(sesion: 'Cita', sesionesPlural: 'Citas'),
        ),
      ),
      resumenHoyProvider.overrideWith(
        (ref) async => ResumenHoy.desdeJson({
          'fecha': '2026-10-03',
          'modalidad': 'citas',
          'agenda': {
            'totales': {
              'sesiones': 6,
              'esperados': 6,
              'llegaron': 2,
              'sin_marcar': 0,
              'por_atender': 3,
              'por_cobrar': 1,
            },
            'sesiones': [
              {
                'id': 'c1',
                'tipo': 'cita',
                'oferta': 'Corte y barba',
                'instructor': 'Luis',
                'sucursal': 'Centro',
                'cliente': 'Dana López',
                'inicia_en': _iso(a(23, 30)),
                'esperados': 1,
                'sin_marcar': 0,
                'momento': 'proxima',
              },
            ],
          },
          'libres': [
            {
              'profesional': 'Ana',
              'sucursal': 'Centro',
              'huecos': 2,
              'siguiente': _iso(a(17, 30)),
            },
            {'profesional': 'Luis', 'sucursal': 'Centro', 'huecos': 0},
          ],
        }),
      ),
      climaNegocioProvider.overrideWith((ref) async => null),
      resumenMesProvider.overrideWith((ref) async => null),
    ]);

    expect(find.text('QUIÉN VIENE DESPUÉS'), findsOneWidget);
    // En citas importa quién viene; el servicio va debajo.
    expect(find.text('Dana López'), findsOneWidget);
    expect(
      find.textContaining('Corte y barba · Luis · Centro'),
      findsOneWidget,
    );
    expect(find.text('Citas hoy'), findsOneWidget);
    expect(find.text('Por atender'), findsOneWidget);
    expect(find.text('Por cobrar'), findsOneWidget);
    expect(find.text('Espacios libres hoy'), findsOneWidget);
    expect(find.text('2 espacios'), findsOneWidget);
    expect(find.text('desde las 17:30'), findsOneWidget);
    expect(find.text('Sin espacios'), findsOneWidget);
  });
}
