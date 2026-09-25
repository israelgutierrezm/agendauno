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

/// Notificaciones push (Firebase Cloud Messaging): inicia Firebase, pide permiso y
/// registra el teléfono en el negocio de la sesión; al cerrar sesión lo quita. Con
/// la app cerrada o en segundo plano, el sistema muestra la notificación solo; con
/// la app abierta se muestra como aviso. Sin configuración de Firebase (o fuera de
/// Android/iOS) no hace nada.
class PushController {
  PushController(this._repo, {FirebaseOptions? opciones})
    : _opciones = opciones ?? FirebaseConfig.opciones;

  final DispositivosRepository _repo;
  final FirebaseOptions? _opciones;
  bool _listo = false;
  Sesion? _sesion;

  /// ¿La app puede recibir push? (Firebase configurado y teléfono Android/iOS).
  bool get disponible => _opciones != null && _esTelefono;

  static bool get _esTelefono =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  static String get plataforma =>
      defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android';

  Future<void> iniciar(MostrarAviso mostrar) async {
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
    });
    // Firebase puede cambiar el token: se vuelve a registrar con la sesión activa.
    FirebaseMessaging.instance.onTokenRefresh.listen((token) {
      final sesion = _sesion;
      if (sesion != null) {
        unawaited(_registrarToken(sesion, token));
      }
    });
  }

  /// Pide permiso (la primera vez) y registra este teléfono para la sesión.
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
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null) {
        await _registrarToken(sesion, token);
      }
    } on Exception {
      // Sin push la app sigue funcionando; se reintenta al volver a abrirla.
    }
  }

  /// Deja de recibir push de esta sesión. Va antes de cerrarla (aún autenticada).
  Future<void> olvidar(Sesion sesion) async {
    _sesion = null;
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

  Future<void> _registrarToken(Sesion sesion, String token) async {
    try {
      await _repo.registrar(sesion.slug, token, plataforma);
    } on DioException {
      // Se reintenta en la siguiente apertura o cambio de token.
    }
  }
}

final pushProvider = Provider<PushController>(
  (ref) => PushController(ref.read(dispositivosRepositoryProvider)),
);
