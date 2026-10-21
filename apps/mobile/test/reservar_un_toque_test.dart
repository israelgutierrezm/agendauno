import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/cuenta/application/cuenta_controller.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/presentation/cuenta_widgets.dart';

/// Reservar desde la app del cliente: dos toques seguidos mandan UNA reserva. Antes
/// el segundo llegaba al servidor, que lo rechazaba («Ya existe una reserva»): se
/// veía un error después del «Reservado.» y la hoja ya cerrada tocaba su estado.

/// Lo que pidió la hoja (el controlador se crea de nuevo al descartarse).
class _Registro {
  final respuesta = Completer<void>();
  var reservas = 0;
}

class _Cuenta extends CuentaController {
  _Cuenta(this.registro);

  final _Registro registro;

  @override
  Future<MiCuenta> build() async =>
      const MiCuenta(derechos: [], reservas: [], clases: []);

  @override
  Future<({String estado, String? ordenId})> reservar(
    String sesionId, {
    bool esperar = false,
    int? lugar,
  }) async {
    registro.reservas++;
    if (registro.reservas > 1) {
      throw DioException(
        requestOptions: RequestOptions(path: '/mi/reservas'),
        response: Response(
          requestOptions: RequestOptions(path: '/mi/reservas'),
          statusCode: 422,
          data: {'message': 'Ya existe una reserva para esta clase.'},
        ),
      );
    }
    await registro.respuesta.future;
    return (estado: 'confirmada', ordenId: null);
  }
}

void main() {
  testWidgets('dos toques en «Reservar» mandan una sola reserva', (
    tester,
  ) async {
    final registro = _Registro();
    final manana = DateTime.now().add(const Duration(days: 1));
    final clase = ClaseMiembro(
      id: 's1',
      oferta: 'Pole Nivel 1',
      sucursal: 'Roma Norte',
      iniciaEn: DateTime(
        manana.year,
        manana.month,
        manana.day,
        10,
      ).toUtc().toIso8601String(),
      capacidad: 10,
      ocupados: 3,
    );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          cuentaProvider.overrideWith(() => _Cuenta(registro)),
          // Sin vista previa: el botón «Reservar» de siempre.
          antesDeReservarProvider.overrideWith((ref, id) async => null),
        ],
        child: MaterialApp(
          home: Scaffold(
            body: Builder(
              builder: (context) => TextButton(
                onPressed: () => mostrarDetalleClase(context, clase),
                child: const Text('Abrir'),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('Abrir'));
    await tester.pumpAndSettle();

    final boton = find.widgetWithText(FilledButton, 'Reservar');
    await tester.tap(boton);
    await tester.pump();
    await tester.tap(boton, warnIfMissed: false);
    await tester.pump();
    registro.respuesta.complete();
    await tester.pumpAndSettle();

    expect(registro.reservas, 1);
    expect(find.text('Reservado.'), findsOneWidget);
    expect(find.textContaining('Ya existe una reserva'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
