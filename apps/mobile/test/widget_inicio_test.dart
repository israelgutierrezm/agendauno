import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/network/participante_en_uso.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/corte_planes.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_screen.dart';
import 'package:agendauno/features/widget_inicio/application/widget_inicio.dart';
import 'package:agendauno/features/widget_inicio/data/datos_widget.dart';

/// El widget de la pantalla de inicio del cliente: la parte Dart que arma lo que
/// muestra (su próxima reserva o cita y su saldo) con lo que la app ya cargó, y
/// cuándo se escribe. El widget nativo no se prueba aquí.

/// Guarda en memoria lo que se le pide (en el teléfono, home_widget).
class _AlmacenEnMemoria implements AlmacenWidget {
  final escrituras = <Map<String, String?>>[];

  @override
  Future<void> guardar(Map<String, String?> claves) async =>
      escrituras.add(claves);
}

class _CuentaFalsa extends CuentaController {
  _CuentaFalsa(this.cuenta);

  final MiCuenta cuenta;

  @override
  Future<MiCuenta> build() async => cuenta;
}

class _ParticipanteFijo extends ParticipanteEnUso {
  @override
  String? build() => 'persona-hija';
}

ReservaMiembro _reserva(
  String id, {
  required String inicia,
  String? termina,
  String estado = 'confirmada',
  TipoSesion tipo = TipoSesion.clase,
  String? oferta,
}) => ReservaMiembro(
  id: id,
  estado: estado,
  tipo: tipo,
  oferta: oferta ?? 'Clase $id',
  sucursal: 'Roma Norte',
  iniciaEn: inicia,
  terminaEn: termina,
  instructor: 'Iván Instructor',
  asiste: 'Sofía (hija)',
  desfaseMin: -360,
);

const _clases = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Vale',
  rol: 'miembro',
  estudioNombre: 'Estudio Demo',
  desfaseNegocioMin: -360,
  modalidad: Modalidad.clases,
);

const _citas = Sesion(
  slug: 'demo',
  bearer: 't',
  nombre: 'Vale',
  rol: 'miembro',
  estudioNombre: 'Barbería Demo',
  modalidad: Modalidad.citas,
  terminologia: Terminologia(sesion: 'Cita'),
);

/// 2026-10-01 06:00 en la Ciudad de México.
final _ahora = DateTime.utc(2026, 10, 1, 12);

void main() {
  group('lo que muestra', () {
    test(
      'las próximas confirmadas o por pagar que no han terminado, hasta tres',
      () {
        final cuenta = MiCuenta(
          derechos: const [
            DerechoMiembro(
              ilimitado: false,
              disponible: 6000,
              producto: 'Pack 8',
              vence: '2026-11-14',
            ),
          ],
          reservas: [
            // Ya terminó: no se muestra.
            _reserva(
              'pasada',
              inicia: '2026-10-01T10:00:00Z',
              termina: '2026-10-01T11:00:00Z',
            ),
            // En lista de espera: aún no es suya.
            _reserva(
              'espera',
              inicia: '2026-10-01T14:00:00Z',
              estado: 'en_espera',
            ),
            _reserva(
              'p1',
              inicia: '2026-10-02T15:00:00Z',
              termina: '2026-10-02T16:00:00Z',
              oferta: 'Pole Nivel 1',
            ),
            _reserva(
              'p2',
              inicia: '2026-10-03T15:00:00Z',
              estado: 'pendiente_pago',
            ),
            _reserva('p3', inicia: '2026-10-04T15:00:00Z'),
            _reserva('p4', inicia: '2026-10-05T15:00:00Z'),
          ],
          clases: const [],
        );

        final datos = DatosWidgetInicio.desdeCuenta(
          cuenta,
          _clases,
          ahora: _ahora,
        );

        expect(datos.proximas, hasLength(ProximaWidget.maximo));
        final primera = datos.proximas.first;
        expect(primera.etiqueta, 'Tu próxima clase');
        expect(primera.titulo, 'Pole Nivel 1');
        // En la hora de su sede.
        expect(primera.cuando, 'vie 2 oct · 09:00');
        expect(primera.donde, 'Roma Norte');
        expect(primera.aviso, isNull);
        expect(
          primera.terminaMs,
          DateTime.utc(2026, 10, 2, 16).millisecondsSinceEpoch,
        );
        expect(datos.proximas[1].aviso, 'Pendiente de pago');
        // Sin hora de fin, se da por terminada a la hora.
        expect(
          datos.proximas[1].terminaMs,
          DateTime.utc(2026, 10, 3, 16).millisecondsSinceEpoch,
        );
        expect(datos.negocio, 'Estudio Demo');
        expect(datos.planTitulo, 'Mis créditos');
        expect(datos.plan, '6 créditos · vence el 14 nov');
        expect(datos.accion, 'Reserva tu próxima clase');
      },
    );

    test('solo lo que el cliente ya ve: ni con quién ni para quién', () {
      final cuenta = MiCuenta(
        derechos: const [],
        reservas: [_reserva('p1', inicia: '2026-10-02T15:00:00Z')],
        clases: const [],
      );

      final claves = DatosWidgetInicio.desdeCuenta(
        cuenta,
        _clases,
        ahora: _ahora,
      ).aClaves();

      expect(claves.keys, ClavesWidget.todas);
      final todo = jsonEncode(claves);
      expect(todo, isNot(contains('Iván')));
      expect(todo, isNot(contains('Sofía')));
      final proximas = jsonDecode(claves[ClavesWidget.proximas]!) as List;
      expect(proximas.single, {
        'etiqueta': 'Tu próxima clase',
        'titulo': 'Clase p1',
        'cuando': 'vie 2 oct · 09:00',
        'donde': 'Roma Norte',
        'aviso': null,
        'termina': DateTime.utc(2026, 10, 2, 16).millisecondsSinceEpoch,
      });
      // Sin plan y sin saber si el negocio usa saldo: no se dice nada (como su
      // Inicio); si lo usa, que no tiene.
      expect(claves[ClavesWidget.plan], isNull);
      expect(
        DatosWidgetInicio.desdeCuenta(
          const MiCuenta(
            derechos: [],
            reservas: [],
            clases: [],
            portal: PortalCliente(
              creditos: true,
              pase: false,
              expediente: false,
            ),
          ),
          _clases,
          ahora: _ahora,
        ).plan,
        'Sin paquete activo',
      );
    });

    test('en citas: la cita, agendar y sin bono no hay saldo que mostrar', () {
      final cuenta = MiCuenta(
        derechos: const [],
        reservas: [
          _reserva(
            'c1',
            inicia: '2026-10-02T15:00:00Z',
            tipo: TipoSesion.cita,
            oferta: 'Corte y barba',
          ),
        ],
        clases: const [],
      );

      final datos = DatosWidgetInicio.desdeCuenta(
        cuenta,
        _citas,
        ahora: _ahora,
      );

      expect(datos.proximas.single.etiqueta, 'Tu próxima cita');
      expect(datos.proximas.single.titulo, 'Corte y barba');
      expect(datos.plan, isNull);
      expect(datos.planTitulo, isNull);
      expect(datos.vacio, 'Nada agendado por ahora');
      expect(datos.accion, 'Agenda tu próxima cita');
    });

    test('sin nada próximo, la lista va vacía', () {
      final datos = DatosWidgetInicio.desdeCuenta(
        const MiCuenta(derechos: [], reservas: [], clases: []),
        _clases,
        ahora: _ahora,
      );

      expect(datos.aClaves()[ClavesWidget.proximas], '[]');
      expect(
        DatosWidgetInicio.sinSesion.values.every((v) => v == null),
        isTrue,
      );
    });
  });

  group('cuándo se escribe', () {
    test('solo si cambió, en orden; al salir se limpia', () async {
      final almacen = _AlmacenEnMemoria();
      final widget = WidgetInicio(almacen);
      final datos = DatosWidgetInicio.desdeCuenta(
        MiCuenta(
          derechos: const [],
          reservas: [_reserva('p1', inicia: '2026-10-02T15:00:00Z')],
          clases: const [],
        ),
        _clases,
        ahora: _ahora,
      );

      await widget.mostrar(datos);
      await widget.mostrar(datos);
      await widget.limpiar();
      await widget.limpiar();

      expect(almacen.escrituras, hasLength(2));
      expect(almacen.escrituras.first[ClavesWidget.negocio], 'Estudio Demo');
      expect(almacen.escrituras.last.values.every((v) => v == null), isTrue);
    });

    test('si una escritura falla, la siguiente sigue', () async {
      final almacen = _AlmacenQueFalla();
      final widget = WidgetInicio(almacen);

      await widget.limpiar();
      await widget.mostrar(
        DatosWidgetInicio.desdeCuenta(
          const MiCuenta(derechos: [], reservas: [], clases: []),
          _clases,
          ahora: _ahora,
        ),
      );

      expect(almacen.intentos, 2);
    });

    testWidgets('al cargar su cuenta, el widget queda con lo mismo', (
      tester,
    ) async {
      final almacen = await _montarCuenta(tester);

      expect(almacen.escrituras, hasLength(1));
      final proximas = jsonDecode(
        almacen.escrituras.single[ClavesWidget.proximas]!,
      );
      expect((proximas as List).single['titulo'], 'Pole Nivel 1');
    });

    testWidgets('con la cuenta de una persona a su cargo, no se toca', (
      tester,
    ) async {
      final almacen = await _montarCuenta(tester, participante: true);

      expect(almacen.escrituras, isEmpty);
    });
  });
}

class _AlmacenQueFalla implements AlmacenWidget {
  var intentos = 0;

  @override
  Future<void> guardar(Map<String, String?> claves) async {
    intentos++;
    if (intentos == 1) {
      throw StateError('sin widget');
    }
  }
}

Future<_AlmacenEnMemoria> _montarCuenta(
  WidgetTester tester, {
  bool participante = false,
}) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final almacen = _AlmacenEnMemoria();
  final manana = DateTime.now().add(const Duration(days: 1));
  final inicio = DateTime(manana.year, manana.month, manana.day, 9);
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sesionInicialProvider.overrideWithValue(_clases),
        almacenWidgetProvider.overrideWithValue(almacen),
        if (participante)
          participanteEnUsoProvider.overrideWith(_ParticipanteFijo.new),
        cuentaProvider.overrideWith(
          () => _CuentaFalsa(
            MiCuenta(
              derechos: const [],
              reservas: [
                _reserva(
                  'r1',
                  inicia: inicio.toUtc().toIso8601String(),
                  termina: inicio
                      .add(const Duration(hours: 1))
                      .toUtc()
                      .toIso8601String(),
                  oferta: 'Pole Nivel 1',
                ),
              ],
              clases: const [],
            ),
          ),
        ),
        climaProvider.overrideWith((ref) async => null),
        cortePlanesProvider.overrideWith((ref) async => const <PlanCorte>[]),
      ],
      child: const MaterialApp(home: CuentaScreen()),
    ),
  );
  await tester.pumpAndSettle();
  return almacen;
}
