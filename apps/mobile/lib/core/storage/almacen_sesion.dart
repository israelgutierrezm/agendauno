import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Guarda la sesión tenant-local en el almacén CIFRADO del dispositivo (Keychain en
/// iOS, Keystore en Android) para no pedir iniciar sesión cada vez que se abre la
/// app. Guarda un mapa JSON; `core` no conoce el modelo de la sesión.
class AlmacenSesion {
  AlmacenSesion([FlutterSecureStorage? almacen])
    : _almacen = almacen ?? const FlutterSecureStorage();

  static const _clave = 'agendauno.sesion';

  final FlutterSecureStorage _almacen;

  /// La sesión guardada, o null si no hay (o si lo guardado es ilegible).
  Future<Map<String, dynamic>?> leer() async {
    try {
      final crudo = await _almacen.read(key: _clave);
      if (crudo == null) {
        return null;
      }
      final datos = jsonDecode(crudo);
      return datos is Map<String, dynamic> ? datos : null;
    } catch (_) {
      return null;
    }
  }

  Future<void> guardar(Map<String, dynamic> datos) =>
      _almacen.write(key: _clave, value: jsonEncode(datos));

  Future<void> borrar() => _almacen.delete(key: _clave);
}

/// Almacén de la sesión (se sobrescribe en `main` con la instancia real).
final almacenSesionProvider = Provider<AlmacenSesion>((ref) => AlmacenSesion());
