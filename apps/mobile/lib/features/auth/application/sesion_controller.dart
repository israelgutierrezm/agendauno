import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/auth_token.dart';
import '../../../core/network/dio_client.dart';
import '../data/sesion.dart';

/// Estado y acciones de la sesion tenant-local (login por estudio, sin login
/// global). Al iniciar sesion guarda el bearer para que Dio autentique las
/// siguientes peticiones; al cerrar lo limpia.
class SesionController extends Notifier<Sesion?> {
  @override
  Sesion? build() => null;

  Future<void> iniciar(String slug, String email, String password) async {
    final Dio dio = ref.read(dioProvider);
    final res = await dio.post<Map<String, dynamic>>(
      '/api/v1/app/$slug/login',
      data: {'email': email, 'password': password},
    );

    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
    final bearer = (data['token'] ?? '') as String;
    final usuario = (data['usuario'] ?? {}) as Map<String, dynamic>;
    final estudio = data['estudio'] as Map<String, dynamic>?;

    ref.read(authTokenProvider.notifier).establecer(bearer);
    state = Sesion.desdeJson(slug, bearer, usuario, estudio);
  }

  void cerrar() {
    ref.read(authTokenProvider.notifier).establecer(null);
    state = null;
  }
}

final sesionProvider = NotifierProvider<SesionController, Sesion?>(SesionController.new);
