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
import 'package:agendauno/features/cuenta/data/eventos_cuenta.dart';
import 'package:agendauno/features/cuenta/presentation/reprogramar_sheet.dart';
import 'package:agendauno/features/inicio/data/resumen_hoy.dart';

/// La cuenta del cliente lee el contrato de la agenda (ADR 0104): `tipo` y el
/// bloque de su tipo (`clase` u `cita`), con lo que calcula el servidor. Un tipo
/// desconocido es un error explícito. Datos sintéticos.

/// API falso: GET de reprogramar con la respuesta dada.
class _ApiReprogramar implements HttpClientAdapter {
  _ApiReprogramar(this.respuesta);

  final Map<String, dynamic> respuesta;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async => ResponseBody.fromString(
    jsonEncode({'data': respuesta}),
    200,
    headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    },
  );

  @override
  void close({bool force = false}) {}
}

class _CuentaVacia extends CuentaController {
  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);
}

void main() {
  group('reservas de /mi/perfil', () {
    test('una cita trae en qué va su atención y su pago', () {
      final r = ReservaMiembro.desdeJson({
        'id': 'r1',
        'sesion_id': 's1',
        'tipo': 'cita',
        'estado': 'pendiente_pago',
        'orden_id': 'o1',
        'clase': null,
        'ocupacion': null,
        'cita': {
          'reserva_id': 'r1',
          'estado': 'pendiente_pago',
          'asistencia': null,
          'orden_id': 'o1',
          'nota': 'Solo la barba',
          'asiste': null,
          'estado_atencion': 'confirmada',
          'estado_pago': 'por_pagar',
        },
      });

      expect(r.tipo, TipoSesion.cita);
      expect(r.esCita, isTrue);
      expect(r.clase, isNull);
      expect(r.cita!.nota, 'Solo la barba');
      expect(r.cita!.estadoAtencion, EstadoCita.confirmada);
      expect(r.cita!.estadoPago, EstadoPagoCita.porPagar);
      expect(r.cita!.estadoPago!.pendiente, isTrue);
    });

    test('una clase trae su cupo y su ocupación, sin bloque de cita', () {
      final r = ReservaMiembro.desdeJson({
        'id': 'r2',
        'sesion_id': 's2',
        'tipo': 'clase',
        'estado': 'en_espera',
        'clase': {
          'capacidad': 10,
          'ocupados': 10,
          'libres': 0,
          'en_espera': 2,
          'lugares': 0,
          'de_pago': false,
          'precio_minor': null,
        },
        'ocupacion': {'ocupados': 10, 'capacidad': 10, 'porcentaje': 100},
        'cita': null,
      });

      expect(r.esCita, isFalse);
      expect(r.clase!.llena, isTrue);
      expect(r.clase!.enEspera, 2);
      expect(r.ocupacion!.porcentaje, 100);
      expect(r.cita, isNull);
    });

    test('un tipo desconocido es un error explícito', () {
      expect(
        () => ReservaMiembro.desdeJson({
          'id': 'r3',
          'tipo': 'evento',
          'estado': 'confirmada',
        }),
        throwsA(isA<TipoSesionDesconocido>()),
      );
      expect(
        () => ReservaMiembro.desdeJson({'id': 'r4', 'estado': 'confirmada'}),
        throwsA(isA<TipoSesionDesconocido>()),
      );
    });
  });

  group('clases de /mi/agenda', () {
    test('los lugares libres son los que cuenta el servidor', () {
      final c = ClaseMiembro.desdeJson({
        'id': 's1',
        'tipo': 'clase',
        'capacidad': 10,
        'ocupados': 8,
        'clase': {
          'capacidad': 10,
          'ocupados': 8,
          'libres': 2,
          'en_espera': 0,
          'lugares': 0,
          'de_pago': true,
          'precio_minor': 15000,
        },
        'cita': null,
        'ocupacion': {'ocupados': 8, 'capacidad': 10, 'porcentaje': 80},
      });

      expect(c.llena, isFalse);
      expect(c.libres, 2);
      expect(c.clase!.dePago, isTrue);
      expect(c.clase!.precioMinor, 15000);
      expect(c.ocupacion!.porcentaje, 80);
      expect(lugaresTexto(c), '2 de 10 lugares');
    });

    test('llena según el servidor; sin cupo no dice lugares', () {
      final llena = ClaseMiembro.desdeJson({
        'id': 's1',
        'tipo': 'clase',
        'clase': {'capacidad': 6, 'ocupados': 6, 'libres': 0, 'en_espera': 1},
      });
      final sinCupo = ClaseMiembro.desdeJson({
        'id': 's2',
        'tipo': 'clase',
        'clase': {'capacidad': null, 'ocupados': 40, 'libres': null},
      });

      expect(lugaresTexto(llena), 'Llena');
      expect(sinCupo.llena, isFalse);
      expect(lugaresTexto(sinCupo), '');
    });

    test('una cita en la lista de clases es un error, no una clase', () {
      expect(
        () => ClaseMiembro.desdeJson({'id': 's1', 'tipo': 'cita'}),
        throwsA(
          isA<TipoSesionDesconocido>().having(
            (e) => e.esperado,
            'esperado',
            TipoSesion.clase,
          ),
        ),
      );
    });
  });

  group('reprogramar', () {
    test('el tipo decide qué se elige; uno desconocido es un error', () {
      expect(
        OpcionesReprogramar.desdeJson({
          'puede': true,
          'tipo': 'clase',
          'restantes': 1,
          'sesiones': <Object>[],
        }).tipo,
        TipoSesion.clase,
      );
      // Antes, sin tipo se suponía cita.
      expect(
        () => OpcionesReprogramar.desdeJson({'puede': false, 'restantes': 0}),
        throwsA(isA<TipoSesionDesconocido>()),
      );
    });

    testWidgets('la hoja dice que no entendió la respuesta en lugar de '
        'quedarse cargando', (tester) async {
      final repo = CuentaRepository(
        Dio()
          ..httpClientAdapter = _ApiReprogramar({
            'puede': true,
            'tipo': 'evento',
            'restantes': 1,
          }),
        'demo',
      );
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
            cuentaRepositoryProvider.overrideWithValue(repo),
            cuentaProvider.overrideWith(_CuentaVacia.new),
          ],
          child: const MaterialApp(
            home: Scaffold(
              body: ReprogramarSheet(
                ReservaMiembro(
                  id: 'r1',
                  estado: 'confirmada',
                  tipo: TipoSesion.clase,
                  oferta: 'Pole Nivel 1',
                ),
              ),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(find.textContaining('«evento»'), findsOneWidget);
    });
  });

  test('el Inicio del negocio: un tipo desconocido es un error', () {
    Map<String, dynamic> resumen(Object? tipo) => {
      'fecha': '2026-10-01',
      'agenda': {
        'totales': {'sesiones': 1},
        'sesiones': [
          {
            'id': 's1',
            'tipo': tipo,
            'oferta': 'Corte',
            'cliente': 'Dana',
            'inicia_en': '2026-10-01T16:00:00Z',
            'momento': 'proxima',
          },
        ],
      },
    };

    expect(
      ResumenHoy.desdeJson(resumen('cita')).agenda!.lista.single.nombre,
      'Corte · Dana',
    );
    expect(
      () => ResumenHoy.desdeJson(resumen('taller')),
      throwsA(isA<TipoSesionDesconocido>()),
    );
  });
}
