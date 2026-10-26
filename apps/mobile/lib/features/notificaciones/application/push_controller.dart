import 'dart:async';

import 'package:dio/dio.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/firebase_config.dart';
import '../../auth/data/sesion.dart';
import '../data/dispositivos_repository.dart';

/// Muestra un aviso que llegó con la app abierta (título y texto).
typedef MostrarAviso = void Function(String titulo, String cuerpo);

/// Se tocó una notificación: sus datos (`estudio`, `mensaje`, `tipo`).
typedef AbrirAviso = void Function(Map<String, dynamic> datos);

/// Notificaciones push (Firebase Cloud Messaging): inicia Firebase, pide permiso y
/// registra el teléfono en el negocio de la sesión; al cerrar sesión lo quita. Con
/// la app cerrada o en segundo plano, el sistema muestra la notificación solo, y al
/// tocarla la app abre lo suyo; con la app abierta se muestra como aviso. Sin
/// configuración de Firebase (o fuera de Android/iOS) no hace nada.
class PushController {
  PushController(this._repo, {FirebaseOptions? opciones})
    : _opciones = opciones ?? FirebaseConfig.opciones;

  final DispositivosRepository _repo;
  final FirebaseOptions? _opciones;
  bool _listo = false;
  Sesion? _sesion;

  /// El token que ya quedó registrado para esta sesión (negocio y bearer): al
  /// volver a la app no se manda otra vez si no cambió.
  String? _registrado;

  /// ¿La app puede recibir push? (Firebase configurado y teléfono Android/iOS).
  bool get disponible => _opciones != null && _esTelefono;

  static bool get _esTelefono =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  static String get plataforma =>
      defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android';

  /// [recibido]: llegó un aviso con la app abierta (sus datos), p. ej. para leer de
  /// nuevo la cuenta: una reserva cambió.
  Future<void> iniciar(
    MostrarAviso mostrar, {
    AbrirAviso? abrir,
    AbrirAviso? recibido,
  }) async {
    if (!disponible || _listo) {
      return;
    }
    try {
      await Firebase.initializeApp(options: _opciones);
    } on FirebaseException {
      return;
    }
    _listo = true;

    FirebaseMessaging.onMessage.listen((mensaje) {
      final n = mensaje.notification;
      if (n != null) {
        mostrar(n.title ?? '', n.body ?? '');
      }
      recibido?.call(mensaje.data);
    });
    if (abrir != null) {
      // Se tocó con la app en segundo plano, o la abrió desde cerrada.
      FirebaseMessaging.onMessageOpenedApp.listen((m) => abrir(m.data));
      try {
        final inicial = await FirebaseMessaging.instance.getInitialMessage();
        if (inicial != null) {
          abrir(inicial.data);
        }
      } on Exception {
        // Sin la notificación inicial, la app abre en su Inicio.
      }
    }
    // Firebase puede cambiar el token: se vuelve a registrar con la sesión activa.
    FirebaseMessaging.instance.onTokenRefresh.listen((token) {
      final sesion = _sesion;
      if (sesion != null) {
        unawaited(_registrarToken(sesion, token));
      }
    });
  }

  /// Pide permiso (la primera vez) y registra este teléfono para la sesión. Se
  /// llama al entrar y al volver a la app (la persona pudo activar los avisos en
  /// Ajustes mientras tanto); si el mismo token ya quedó registrado, no se manda otra
  /// vez. En iPhone, Firebase da su token solo cuando ya tiene el de APNs: se espera
  /// con reintentos (si no llega, se intenta la próxima vez que vuelva a la app).
  Future<void> registrar(Sesion sesion) async {
    if (!_listo) {
      return;
    }
    _sesion = sesion;
    try {
      final permiso = await FirebaseMessaging.instance.requestPermission();
      if (permiso.authorizationStatus == AuthorizationStatus.denied) {
        return;
      }
      if (defaultTargetPlatform == TargetPlatform.iOS &&
          await esperarApns(FirebaseMessaging.instance.getAPNSToken) == null) {
        return;
      }
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null && _registrado != _clave(sesion, token)) {
        await _registrarToken(sesion, token);
      }
    } on Exception {
      // Sin push la app sigue funcionando; se reintenta al volver a abrirla.
    }
  }

  /// El token de APNs (iPhone), esperándolo: recién abierta la app puede tardar en
  /// llegar y, sin él, `getToken` falla. Hasta [intentos] veces, con pausas que
  /// crecen ([espera], 2×[espera]…). Null si no llegó.
  static Future<String?> esperarApns(
    Future<String?> Function() leer, {
    int intentos = 6,
    Duration espera = const Duration(milliseconds: 500),
    Future<void> Function(Duration) dormir = Future<void>.delayed,
  }) async {
    for (var i = 0; i < intentos; i++) {
      try {
        final token = await leer();
        if (token != null && token.isNotEmpty) {
          return token;
        }
      } on Exception {
        // Aún no está: se reintenta.
      }
      if (i < intentos - 1) {
        await dormir(espera * (i + 1));
      }
    }
    return null;
  }

  static String _clave(Sesion sesion, String token) =>
      '${sesion.slug}|${sesion.bearer}|$token';

  /// Deja de recibir push de esta sesión. Va antes de cerrarla (aún autenticada).
  Future<void> olvidar(Sesion sesion) async {
    _sesion = null;
    _registrado = null;
    if (!_listo) {
      return;
    }
    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null) {
        await _repo.quitar(sesion.slug, token);
      }
    } on Exception {
      // Sin red: el servidor olvida el teléfono cuando otro inicie sesión en él.
    }
  }

  /// La sesión terminó en el servidor (token revocado): deja de volver a registrar
  /// el teléfono con ella. Sin token ya no se puede pedir que lo olvide; el
  /// servidor lo reasigna cuando otra sesión lo registre.
  void soltar() {
    _sesion = null;
    _registrado = null;
  }

  Future<void> _registrarToken(Sesion sesion, String token) async {
    try {
      await _repo.registrar(sesion.slug, token, plataforma);
      _registrado = _clave(sesion, token);
    } on DioException {
      // Se reintenta en la siguiente apertura o cambio de token.
    }
  }
}

final pushProvider = Provider<PushController>(
  (ref) => PushController(ref.read(dispositivosRepositoryProvider)),
);
