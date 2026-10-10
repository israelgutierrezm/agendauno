import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/network/mensaje_error.dart';

/// Qué se le dice a la persona cuando una petición falla: el API responde todo
/// 422 con el mismo `message` y la razón real en `meta.errors`; además manda un
/// `code` estable. Datos sintéticos.

DioException _error(
  int? estado, {
  Object? cuerpo,
  DioExceptionType tipo = DioExceptionType.badResponse,
}) {
  final opciones = RequestOptions(path: '/api/v1/app/demo/mi/citas');
  return DioException(
    requestOptions: opciones,
    type: tipo,
    response: estado == null
        ? null
        : Response(requestOptions: opciones, statusCode: estado, data: cuerpo),
  );
}

void main() {
  test('un 422 dice la razón de meta.errors, no el mensaje fijo', () {
    final e = _error(
      422,
      cuerpo: {
        'code': 'VALIDATION_FAILED',
        'message': 'Los datos proporcionados no son válidos.',
        'meta': {
          'errors': {
            'email': [
              'Estas credenciales no coinciden con nuestros registros.',
            ],
            'password': ['La contraseña es obligatoria.'],
          },
        },
      },
    );

    expect(
      mensajeDeError(e),
      'Estas credenciales no coinciden con nuestros registros.',
    );
  });

  test('salta los campos sin texto', () {
    final e = _error(
      422,
      cuerpo: {
        'message': 'Los datos proporcionados no son válidos.',
        'meta': {
          'errors': {
            'nota': <String>[],
            'inicia_en_local': ['Ese horario ya no está libre.'],
          },
        },
      },
    );

    expect(mensajeDeError(e), 'Ese horario ya no está libre.');
  });

  test('429: que espere un minuto', () {
    expect(
      mensajeDeError(
        _error(429, cuerpo: {'code': 'TOO_MANY_REQUESTS', 'message': 'Too'}),
      ),
      'Demasiados intentos. Espera un minuto y vuelve a intentar.',
    );
  });

  test('sin respuesta (sin red o tiempo agotado): que revise su conexión', () {
    for (final tipo in [
      DioExceptionType.connectionError,
      DioExceptionType.connectionTimeout,
      DioExceptionType.receiveTimeout,
      DioExceptionType.unknown,
    ]) {
      expect(
        mensajeDeError(_error(null, tipo: tipo)),
        'Sin conexión. Revisa tu internet y vuelve a intentar.',
        reason: '$tipo',
      );
    }
  });

  test('una función que el plan del negocio no incluye se dice para el '
      'cliente', () {
    expect(
      mensajeDeError(
        _error(
          403,
          cuerpo: {
            'code': 'PLAN_FEATURE_NOT_INCLUDED',
            'message': 'Tu plan no incluye venta en línea.',
          },
        ),
      ),
      'Esta opción no está disponible en este negocio.',
    );
  });

  test('con otro código, el mensaje del servidor', () {
    expect(
      mensajeDeError(
        _error(
          422,
          cuerpo: {
            'code': 'PROFESSIONAL_SEATS_EXCEEDED',
            'message': 'Tu plan ya no admite más profesionales.',
          },
        ),
      ),
      'Tu plan ya no admite más profesionales.',
    );
  });

  test('sin nada que decir, el texto por omisión', () {
    expect(
      mensajeDeError(_error(500, cuerpo: '<html>Error</html>')),
      'No se pudo completar la acción.',
    );
    expect(
      mensajeDeError(
        _error(404, cuerpo: {'message': ''}),
        porDefecto: 'No se pudo marcar.',
      ),
      'No se pudo marcar.',
    );
    expect(
      mensajeDeError(StateError('otra cosa'), porDefecto: 'No se pudo.'),
      'No se pudo.',
    );
  });
}
