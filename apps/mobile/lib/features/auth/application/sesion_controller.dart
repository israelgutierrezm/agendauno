import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/auth_token.dart';
import '../../../core/network/dio_client.dart';
import '../../../core/network/sesion_revocada.dart';
import '../../../core/storage/almacen_sesion.dart';
import '../../notificaciones/application/push_controller.dart';
import '../data/sesion.dart';

/// Sesión restaurada del almacén cifrado al abrir la app (la fija `main`).
final sesionInicialProvider = Provider<Sesion?>((ref) => null);

/// Negocio (slug) de la sesión que el servidor dio por terminada, o null. El login
/// avisa que la sesión terminó y deja escrita la dirección del negocio.
class SesionTerminada extends Notifier<String?> {
  @override
  String? build() => null;

  void avisar(String slug) => state = slug;

  void olvidar() => state = null;
}

final sesionTerminadaProvider = NotifierProvider<SesionTerminada, String?>(
  SesionTerminada.new,
);

/// Estado y acciones de la sesion tenant-local (login por estudio, sin login
/// global). Al iniciar sesion guarda el bearer para que Dio autentique las
/// siguientes peticiones y la persiste cifrada en el dispositivo; al cerrar la
/// limpia. Al abrir la app se restaura y se valida contra el servidor.
class SesionController extends Notifier<Sesion?> {
  @override
  Sesion? build() {
    // Un 401 de cualquier pantalla (token revocado) cierra la sesión aquí.
    final aviso = ref.read(avisoSesionRevocadaProvider)
      ..escuchar(_terminadaEnElServidor);
    ref.onDispose(() => aviso.escuchar(null));
    return ref.read(sesionInicialProvider);
  }

  Future<void> iniciar(String slug, String email, String password) async {
    final Dio dio = ref.read(dioProvider);
    final res = await dio.post<Map<String, dynamic>>(
      '/api/v1/app/$slug/login',
      data: {'email': email, 'password': password},
    );
    await _entrar(slug, res.data);
  }

  /// Entra con Google: solo a una cuenta que ya conectó Google desde su perfil
  /// (Google no crea ni activa cuentas, ADR 0093).
  Future<void> iniciarConGoogle(String slug, String idToken) async {
    final res = await ref
        .read(dioProvider)
        .post<Map<String, dynamic>>(
          '/api/v1/app/$slug/auth/google',
          data: {'credential': idToken},
        );
    await _entrar(slug, res.data);
  }

  /// Conecta Google a la cuenta con la que se entró (puede ser otro Gmail).
  Future<void> conectarGoogle(String idToken) async {
    final actual = state;
    if (actual == null) {
      return;
    }
    final res = await ref
        .read(dioProvider)
        .put<Map<String, dynamic>>(
          '/api/v1/app/${actual.slug}/yo/google',
          data: {'credential': idToken},
        );
    await _reflejarUsuario(actual, res.data);
  }

  /// Quita Google: vuelve a entrar con su correo y contraseña.
  Future<void> desconectarGoogle() async {
    final actual = state;
    if (actual == null) {
      return;
    }
    final res = await ref
        .read(dioProvider)
        .delete<Map<String, dynamic>>('/api/v1/app/${actual.slug}/yo/google');
    await _reflejarUsuario(actual, res.data);
  }

  Future<void> _entrar(String slug, Map<String, dynamic>? cuerpo) async {
    final data = (cuerpo?['data'] ?? {}) as Map<String, dynamic>;
    final bearer = (data['token'] ?? '') as String;
    final usuario = (data['usuario'] ?? {}) as Map<String, dynamic>;
    final estudio = data['estudio'] as Map<String, dynamic>?;

    ref.read(authTokenProvider.notifier).establecer(bearer);
    ref.read(sesionTerminadaProvider.notifier).olvidar();
    final sesion = Sesion.desdeJson(slug, bearer, usuario, estudio);
    await ref.read(almacenSesionProvider).guardar(sesion.aJson());
    // Con más de un rol, primero «¿Cómo quieres entrar?».
    state = sesion.tieneVariosRoles
        ? sesion.conUsuario(const {}, eligiendoRol: true)
        : sesion;
  }

  /// Cambia el rol con el que se trabaja (o confirma el que ya tenía al entrar).
  /// El servidor lo guarda en la sesión y desde entonces solo concede sus
  /// permisos; también lo recuerda para la próxima vez.
  Future<void> cambiarRol(String rol) async {
    final actual = state;
    if (actual == null) {
      return;
    }
    if (rol == actual.rol) {
      state = actual.conUsuario(const {}, eligiendoRol: false);
      return;
    }
    final res = await ref
        .read(dioProvider)
        .put<Map<String, dynamic>>(
          '/api/v1/app/${actual.slug}/yo/rol-activo',
          data: {'rol': rol},
        );
    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
    final usuario = (data['usuario'] ?? {}) as Map<String, dynamic>;
    state = actual.conUsuario(usuario, eligiendoRol: false);
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
      // Si mientras tanto se cerró o cambió la sesión, la respuesta ya no aplica.
      if (state?.bearer != actual.bearer) {
        return;
      }
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
      _siRevocada(e, actual);
    }
  }

  /// Al volver a la app: confirma con el servidor que la sesión sigue viva (pudo
  /// cerrarse en la web u otro teléfono mientras estaba en segundo plano). Sin red
  /// no pasa nada; con 401 vuelve al login con el aviso.
  Future<void> revisar() async {
    final actual = state;
    if (actual == null) {
      return;
    }
    try {
      await ref
          .read(dioProvider)
          .get<Map<String, dynamic>>('/api/v1/app/${actual.slug}/yo');
    } on DioException catch (e) {
      _siRevocada(e, actual);
    }
  }

  /// Respaldo del interceptor de la red: un 401 de la misma sesión la termina.
  void _siRevocada(DioException e, Sesion actual) {
    if (e.response?.statusCode == 401 && state?.bearer == actual.bearer) {
      _terminadaEnElServidor();
    }
  }

  /// El servidor ya no reconoce el token (se cerró sesión en otro lado, cambió la
  /// contraseña o dieron de baja la cuenta): se borra del teléfono SIN llamar a
  /// /logout (ya no serviría) y el login dice que la sesión terminó.
  void _terminadaEnElServidor() {
    final actual = state;
    if (actual == null) {
      return;
    }
    ref.read(pushProvider).soltar();
    ref.read(authTokenProvider.notifier).establecer(null);
    ref.read(sesionTerminadaProvider.notifier).avisar(actual.slug);
    state = null;
    unawaited(ref.read(almacenSesionProvider).borrar());
  }

  /// Guarda el nombre y, si tiene ficha de cliente o alumno, su celular, su fecha
  /// de nacimiento y su género (Mi perfil); refleja lo que devuelve el servidor.
  Future<void> guardarPerfil({
    required String nombre,
    String? primerApellido,
    String? segundoApellido,
    String? celular,
    String? fechaNacimiento,
    String? genero,
    bool conCelular = false,
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
            if (conCelular) ...{
              'celular': celular,
              'fecha_nacimiento': fechaNacimiento,
              'genero': genero,
            },
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

  /// Enlace `webcal://` de su calendario personal (clases y citas) para suscribirse
  /// desde la app de calendario del teléfono.
  Future<String?> enlaceCalendario() async =>
      (await _calendario())?['webcal'] as String?;

  /// Enlace del .ics de un solo evento (`reserva-{id}` o `sesion-{id}`) para
  /// "Agregar a mi calendario" (Apple, Outlook y los demás lo abren).
  Future<String?> enlaceEvento(String evento) async {
    final plantilla = (await _calendario())?['evento'] as String?;
    return plantilla?.replaceAll('{evento}', evento);
  }

  Future<Map<String, dynamic>?> _calendario() async {
    final actual = state;
    if (actual == null) {
      return null;
    }
    final res = await ref
        .read(dioProvider)
        .get<Map<String, dynamic>>('/api/v1/app/${actual.slug}/yo/calendario');
    return (res.data?['data'] ?? {}) as Map<String, dynamic>;
  }

  /// Cierra la sesión: deja de recibir sus push, revoca el token en el servidor (si
  /// hay red) y la borra del dispositivo.
  Future<void> cerrar() async {
    final actual = state;
    if (actual != null) {
      await ref.read(pushProvider).olvidar(actual);
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
