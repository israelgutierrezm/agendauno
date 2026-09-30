import 'dart:convert';
import 'dart:typed_data';

import 'package:agendauno/core/errores/reporte_errores.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

/// Guarda lo que la app manda al monitoreo de errores.
class _Api implements HttpClientAdapter {
  final List<Map<String, dynamic>> enviados = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    enviados.add(Map<String, dynamic>.from(options.data as Map));
    return ResponseBody.fromString(
      jsonEncode({
        'data': {'recibido': true},
      }),
      202,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  late _Api api;
  late ReporteErrores reporte;

  setUp(() {
    api = _Api();
    reporte = ReporteErrores(dio: Dio()..httpClientAdapter = api, maximo: 3)
      ..estudio = () => 'barberia';
  });

  test('manda el error con su lugar en nuestro código y el negocio', () async {
    final pila = StackTrace.fromString(
      '#0      Object.noSuchMethod (dart:core-patch/object_patch.dart:38:5)\n'
      '#1      CuentaScreen.build (package:agendauno/features/cuenta/presentation/cuenta_screen.dart:120:14)',
    );

    await reporte.reportar(StateError('Sin reservas'), pila);

    expect(api.enviados, hasLength(1));
    expect(api.enviados.single, containsPair('origen', 'app'));
    expect(api.enviados.single, containsPair('tipo', 'StateError'));
    expect(
      api.enviados.single,
      containsPair(
        'lugar',
        'package:agendauno/features/cuenta/presentation/cuenta_screen.dart:120:14',
      ),
    );
    expect(api.enviados.single, containsPair('estudio', 'barberia'));
  });

  test(
    'no manda errores de red, ni el mismo dos veces, ni más del máximo',
    () async {
      await reporte.reportar(
        DioException(requestOptions: RequestOptions(path: '/x')),
        null,
      );
      expect(api.enviados, isEmpty);

      await reporte.reportar(StateError('Uno'), null);
      await reporte.reportar(StateError('Uno'), null);
      expect(api.enviados, hasLength(1));

      for (var n = 0; n < 5; n++) {
        await reporte.reportar(StateError('Otro $n'), null);
      }
      expect(api.enviados, hasLength(3));
    },
  );
}
