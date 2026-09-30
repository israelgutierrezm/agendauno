import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../config/app_config.dart';

/// Reporta al monitoreo de la plataforma (ADR 0080) los errores de la app que nadie
/// atrapó: los de Flutter al dibujar y los asíncronos sin manejar.
///
/// No reporta los errores de red ni de la API (esos se registran en el servidor).
/// Cada error se manda una sola vez y como mucho 10 por sesión de la app. El servidor
/// tacha lo sensible.
class ReporteErrores {
  ReporteErrores({Dio? dio, this.maximo = 10})
    : _dio =
          dio ??
          Dio(
            BaseOptions(
              baseUrl: AppConfig.apiBaseUrl,
              headers: const {'Accept': 'application/json'},
              connectTimeout: const Duration(seconds: 5),
              receiveTimeout: const Duration(seconds: 5),
            ),
          );

  /// El que usa la app.
  static final ReporteErrores instancia = ReporteErrores();

  final Dio _dio;
  final int maximo;
  final Set<String> _vistos = {};
  int _enviados = 0;

  /// El negocio de la sesión, si hay una.
  String? Function() estudio = () => null;

  void instalar() {
    final anterior = FlutterError.onError;
    FlutterError.onError = (detalles) {
      anterior?.call(detalles);
      reportar(detalles.exception, detalles.stack);
    };
    PlatformDispatcher.instance.onError = (error, pila) {
      debugPrint('Error sin manejar: $error');
      reportar(error, pila);
      return true;
    };
  }

  Future<void> reportar(Object error, StackTrace? pila) async {
    if (error is DioException || _enviados >= maximo) {
      return;
    }
    final tipo = _recortar(error.runtimeType.toString(), 120);
    final mensaje = _recortar(error.toString(), 2000);
    final lugar = lugarDe(pila);
    if (!_vistos.add('$tipo|$mensaje|${lugar ?? ''}')) {
      return;
    }
    _enviados++;

    try {
      await _dio.post<void>(
        '/api/v1/errores',
        data: {
          'origen': 'app',
          'tipo': tipo,
          'mensaje': mensaje,
          'lugar': lugar,
          'traza': pila == null ? null : _recortar(pila.toString(), 8000),
          'version': AppConfig.version,
          'estudio': estudio(),
        },
      );
    } catch (_) {
      // Sin red o sin servidor: no se insiste.
    }
  }

  /// El primer lugar de nuestro código en la traza (`package:agendauno/…`).
  static String? lugarDe(StackTrace? pila) {
    final propio = RegExp(r'\((package:agendauno/[^)]+)\)');
    for (final linea in (pila?.toString() ?? '').split('\n')) {
      final encontrado = propio.firstMatch(linea);
      if (encontrado != null) {
        return _recortar(encontrado.group(1)!, 300);
      }
    }
    return null;
  }

  static String _recortar(String texto, int maximo) =>
      texto.length <= maximo ? texto : texto.substring(0, maximo);
}
