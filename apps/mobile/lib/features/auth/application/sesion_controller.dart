import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/auth_token.dart';
import '../../../core/network/dio_client.dart';
import '../../../core/storage/almacen_sesion.dart';
import '../data/sesion.dart';

/// Sesión restaurada del almacén cifrado al abrir la app (la fija `main`).
final sesionInicialProvider = Provider<Sesion?>((ref) => null);

/// Estado y acciones de la sesion tenant-local (login por estudio, sin login
/// global). Al iniciar sesion guarda el bearer para que Dio autentique las
/// siguientes peticiones y la persiste cifrada en el dispositivo; al cerrar la
/// limpia. Al abrir la app se restaura y se valida contra el servidor.
class SesionController extends Notifier<Sesion?> {
  @override
  Sesion? build() => ref.read(sesionInicialProvider);

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
    await ref.read(almacenSesionProvider).guardar(state!.aJson());
  }

  /// Revalida la sesión restaurada: si el servidor ya no reconoce el token (401),
  /// se cierra; si sí, se actualizan el usuario y el perfil del negocio. Sin red se
  /// conserva la sesión (se reintenta en la siguiente apertura).
  Future<void> refrescar() async {
    final actual = state;
    if (actual == null) {
      return;
    }
    try {
      final res = await ref.read(dioProvider).get<Map<String, dynamic>>('/api/v1/app/${actual.slug}/yo');
      final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
      final usuario = (data['usuario'] ?? {}) as Map<String, dynamic>;
      state = Sesion.desdeJson(actual.slug, actual.bearer, usuario, data['estudio'] as Map<String, dynamic>?);
      await ref.read(almacenSesionProvider).guardar(state!.aJson());
    } on DioException catch (e) {
      if (e.response?.statusCode == 401) {
        await cerrar();
      }
    }
  }

  Future<void> cerrar() async {
    ref.read(authTokenProvider.notifier).establecer(null);
    state = null;
    await ref.read(almacenSesionProvider).borrar();
  }
}

final sesionProvider = NotifierProvider<SesionController, Sesion?>(SesionController.new);
