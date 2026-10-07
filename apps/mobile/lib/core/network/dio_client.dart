import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/app_config.dart';
import 'auth_token.dart';
import 'sesion_revocada.dart';

/// Shared Dio client for the app.
///
/// Every request carries an `X-Correlation-ID` header so a mobile action can be
/// traced across the API and the server logs (see docs/ARCHITECTURE.md).
final dioProvider = Provider<Dio>((ref) {
  final dio = Dio(
    BaseOptions(
      baseUrl: AppConfig.apiBaseUrl,
      headers: const {'Accept': 'application/json'},
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 10),
    ),
  );

  dio.interceptors.add(
    InterceptorsWrapper(
      onRequest: (options, handler) {
        options.headers['X-Correlation-ID'] = _correlationId();

        // Autenticacion tenant-local por bearer (si hay sesion activa).
        final token = ref.read(authTokenProvider);
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }

        handler.next(options);
      },
      onError: (error, handler) {
        // Token revocado con la app abierta: la sesión se cierra en el teléfono
        // y vuelve al login con el aviso (no se queda en «No se pudo cargar»).
        if (esSesionRevocada(error, ref.read(authTokenProvider))) {
          ref.read(avisoSesionRevocadaProvider).avisar();
        }
        handler.next(error);
      },
    ),
  );

  return dio;
});

String _correlationId() {
  final random = Random();
  final suffix = List<String>.generate(
    8,
    (_) => random.nextInt(16).toRadixString(16),
  ).join();

  return 'cid-${DateTime.now().millisecondsSinceEpoch}-$suffix';
}
