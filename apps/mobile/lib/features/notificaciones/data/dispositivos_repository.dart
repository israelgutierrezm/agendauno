import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';

/// Registro del teléfono (token de FCM) en el negocio de la sesión, para que le
/// lleguen las notificaciones push; se quita al cerrar sesión.
class DispositivosRepository {
  DispositivosRepository(this._dio);

  final Dio _dio;

  Future<void> registrar(String slug, String token, String plataforma) =>
      _dio.post<Map<String, dynamic>>(
        '/api/v1/app/$slug/mi/dispositivos',
        data: {'token': token, 'plataforma': plataforma},
      );

  Future<void> quitar(String slug, String token) =>
      _dio.delete<Map<String, dynamic>>(
        '/api/v1/app/$slug/mi/dispositivos',
        data: {'token': token},
      );
}

final dispositivosRepositoryProvider = Provider<DispositivosRepository>(
  (ref) => DispositivosRepository(ref.read(dioProvider)),
);
