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

  /// Crea la cuenta de un alumno nuevo en el negocio (registro público) y deja la
  /// sesión iniciada, igual que el login.
  Future<void> registrarAlumno({
    required String slug,
    required String nombre,
    required String primerApellido,
    required String email,
    required String password,
  }) async {
    final res = await ref
        .read(dioProvider)
        .post<Map<String, dynamic>>(
          '/api/v1/app/$slug/registro-alumno',
          data: {
            'nombre': nombre,
            'primer_apellido': primerApellido.isEmpty ? null : primerApellido,
            'email': email,
            'password': password,
            'password_confirmation': password,
          },
        );

    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
    final bearer = (data['token'] ?? '') as String;
    ref.read(authTokenProvider.notifier).establecer(bearer);
    state = Sesion.desdeJson(
      slug,
      bearer,
      (data['usuario'] ?? {}) as Map<String, dynamic>,
      data['estudio'] as Map<String, dynamic>?,
    );
    await ref.read(almacenSesionProvider).guardar(state!.aJson());
  }

  /// Pide el enlace para elegir una contraseña nueva (llega por correo y se abre en
  /// la web). El servidor responde igual exista o no la cuenta.
  Future<void> pedirRecuperacion(String slug, String email) async {
    await ref
        .read(dioProvider)
        .post<Map<String, dynamic>>(
          '/api/v1/app/$slug/recuperar-contrasena',
          data: {'email': email},
        );
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
      final res = await ref
          .read(dioProvider)
          .get<Map<String, dynamic>>('/api/v1/app/${actual.slug}/yo');
      final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
      final usuario = (data['usuario'] ?? {}) as Map<String, dynamic>;
      state = Sesion.desdeJson(
        actual.slug,
        actual.bearer,
        usuario,
        data['estudio'] as Map<String, dynamic>?,
      );
      await ref.read(almacenSesionProvider).guardar(state!.aJson());
    } on DioException catch (e) {
      if (e.response?.statusCode == 401) {
        await cerrar();
      }
    }
  }

  /// Guarda el nombre (Mi perfil) y refleja lo que devuelve el servidor.
  Future<void> guardarPerfil({
    required String nombre,
    String? primerApellido,
    String? segundoApellido,
  }) async {
    final actual = state;
    if (actual == null) {
      return;
    }
    final res = await ref
        .read(dioProvider)
        .put<Map<String, dynamic>>(
          '/api/v1/app/${actual.slug}/yo/perfil',
          data: {
            'nombre': nombre,
            'primer_apellido': primerApellido,
            'segundo_apellido': segundoApellido,
          },
        );
    final usuario =
        ((res.data?['data'] ?? {}) as Map<String, dynamic>)['usuario']
            as Map<String, dynamic>?;
    if (usuario != null) {
      state = actual.conUsuario(usuario);
      await ref.read(almacenSesionProvider).guardar(state!.aJson());
    }
  }

  /// Sube la foto de perfil (jpg, png o webp de hasta 4 MB) y refleja la nueva URL.
  Future<void> subirFoto(List<int> bytes, String nombreArchivo) async {
    final actual = state;
    if (actual == null) {
      return;
    }
    final res = await ref
        .read(dioProvider)
        .post<Map<String, dynamic>>(
          '/api/v1/app/${actual.slug}/yo/foto',
          data: FormData.fromMap({
            'foto': MultipartFile.fromBytes(bytes, filename: nombreArchivo),
          }),
        );
    await _reflejarUsuario(actual, res.data);
  }

  /// Quita la foto de perfil (vuelven las iniciales).
  Future<void> quitarFoto() async {
    final actual = state;
    if (actual == null) {
      return;
    }
    final res = await ref
        .read(dioProvider)
        .delete<Map<String, dynamic>>('/api/v1/app/${actual.slug}/yo/foto');
    await _reflejarUsuario(actual, res.data);
  }

  Future<void> _reflejarUsuario(
    Sesion actual,
    Map<String, dynamic>? cuerpo,
  ) async {
    final usuario =
        ((cuerpo?['data'] ?? {}) as Map<String, dynamic>)['usuario']
            as Map<String, dynamic>?;
    if (usuario != null) {
      state = actual.conUsuario(usuario);
      await ref.read(almacenSesionProvider).guardar(state!.aJson());
    }
  }

  /// Cambia la contraseña (la actual se pide si ya tenía una).
  Future<void> cambiarContrasena({
    String? actualContrasena,
    required String nueva,
    required String confirmacion,
  }) async {
    final actual = state;
    if (actual == null) {
      return;
    }
    await ref
        .read(dioProvider)
        .put<Map<String, dynamic>>(
          '/api/v1/app/${actual.slug}/yo/contrasena',
          data: {
            'actual': actualContrasena,
            'password': nueva,
            'password_confirmation': confirmacion,
          },
        );
    if (!actual.tieneContrasena) {
      state = actual.conUsuario({'tiene_contrasena': true});
      await ref.read(almacenSesionProvider).guardar(state!.aJson());
    }
  }

  /// Pide cambiar el correo de acceso: llega un enlace al correo nuevo y el cambio
  /// se aplica al abrirlo (en la web). Hasta entonces sigue el anterior.
  Future<void> pedirCambioCorreo({
    required String email,
    String? contrasena,
  }) async {
    final actual = state;
    if (actual == null) {
      return;
    }
    final res = await ref
        .read(dioProvider)
        .post<Map<String, dynamic>>(
          '/api/v1/app/${actual.slug}/yo/correo',
          data: {'email': email, 'password': contrasena},
        );
    await _reflejarUsuario(actual, res.data);
  }

  Future<void> cancelarCambioCorreo() async {
    final actual = state;
    if (actual == null) {
      return;
    }
    final res = await ref
        .read(dioProvider)
        .delete<Map<String, dynamic>>('/api/v1/app/${actual.slug}/yo/correo');
    await _reflejarUsuario(actual, res.data);
  }

  /// Cierra la sesión: revoca el token en el servidor (si hay red) y la borra del
  /// dispositivo.
  Future<void> cerrar() async {
    final actual = state;
    if (actual != null) {
      try {
        await ref
            .read(dioProvider)
            .post<Map<String, dynamic>>('/api/v1/app/${actual.slug}/logout');
      } on DioException {
        // Sin red o token ya revocado: se cierra igual en el dispositivo.
      }
    }
    ref.read(authTokenProvider.notifier).establecer(null);
    state = null;
    await ref.read(almacenSesionProvider).borrar();
  }
}

final sesionProvider = NotifierProvider<SesionController, Sesion?>(
  SesionController.new,
);
