import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../../core/config/app_config.dart';
import '../../../core/network/dio_client.dart';

/// Un negocio del directorio público (`GET /api/v1/directorio`) o de los que se
/// abrieron en este teléfono: con qué se le reconoce antes de entrar.
class NegocioDirectorio {
  const NegocioDirectorio({
    required this.slug,
    required this.nombre,
    this.ciudad,
    this.pais,
    this.logoUrl,
    this.visitadoEn,
  });

  final String slug;
  final String nombre;
  final String? ciudad;
  final String? pais;
  final String? logoUrl;

  /// Cuándo se abrió por última vez en este teléfono (solo en los recientes).
  final DateTime? visitadoEn;

  /// «Roma Norte · MX», o null.
  String? get lugar {
    final partes = [
      ciudad,
      pais,
    ].whereType<String>().where((p) => p.isNotEmpty);
    return partes.isEmpty ? null : partes.join(' · ');
  }

  Map<String, dynamic> aJson() => {
    'slug': slug,
    'nombre': nombre,
    'ciudad': ciudad,
    'pais': pais,
    'logo_url': logoUrl,
    'visitado_en': visitadoEn?.millisecondsSinceEpoch,
  };

  static NegocioDirectorio? desdeJson(Object? j) {
    if (j is! Map) {
      return null;
    }
    final slug = j['slug'];
    final nombre = j['nombre'];
    if (slug is! String ||
        slug.trim().isEmpty ||
        nombre is! String ||
        nombre.trim().isEmpty) {
      return null;
    }
    final visitado = j['visitado_en'];
    return NegocioDirectorio(
      slug: slug.trim(),
      nombre: nombre.trim(),
      ciudad: j['ciudad'] as String?,
      pais: j['pais'] as String?,
      logoUrl: j['logo_url'] as String?,
      visitadoEn: visitado is int
          ? DateTime.fromMillisecondsSinceEpoch(visitado)
          : null,
    );
  }
}

/// El directorio público de negocios de ESTE producto (`producto` en la consulta,
/// como la web: TurnoUno no lista negocios de AgendaUno ni al revés).
class DirectorioRepository {
  DirectorioRepository(this._dio);

  final Dio _dio;

  /// Los primeros 8 que coinciden con [q] (nombre, ciudad o país).
  Future<List<NegocioDirectorio>> buscar(String q) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '/api/v1/directorio',
      queryParameters: {
        'producto': AppConfig.producto.clave,
        if (q.trim().isNotEmpty) 'q': q.trim(),
      },
    );
    return ((res.data?['data'] ?? const []) as List)
        .map(NegocioDirectorio.desdeJson)
        .whereType<NegocioDirectorio>()
        .take(8)
        .toList();
  }
}

final directorioRepositoryProvider = Provider<DirectorioRepository>(
  (ref) => DirectorioRepository(ref.watch(dioProvider)),
);

/// Los negocios que se abrieron en este teléfono (los últimos 5), para entrar de
/// nuevo con un toque, como en la web. Cada producto guarda su lista (TurnoUno no
/// ve los de AgendaUno). Es opcional: si el almacén falla, no pasa nada.
class NegociosRecientes {
  NegociosRecientes([FlutterSecureStorage? almacen])
    : _almacen = almacen ?? const FlutterSecureStorage();

  static const limite = 5;

  final FlutterSecureStorage _almacen;

  static String get _clave =>
      '${AppConfig.producto.clave}.negocios-recientes.v1';

  Future<List<NegocioDirectorio>> leer() async {
    try {
      final crudo = await _almacen.read(key: _clave);
      if (crudo == null) {
        return const [];
      }
      final datos = jsonDecode(crudo);
      if (datos is! List) {
        return const [];
      }
      final lista =
          datos
              .map(NegocioDirectorio.desdeJson)
              .whereType<NegocioDirectorio>()
              .toList()
            ..sort(
              (a, b) => (b.visitadoEn ?? DateTime(0)).compareTo(
                a.visitadoEn ?? DateTime(0),
              ),
            );
      return lista.take(limite).toList();
    } on Object {
      return const [];
    }
  }

  /// Lo pone primero (con la hora de hoy); conserva lo que ya se sabía de él.
  /// Devuelve la lista nueva.
  Future<List<NegocioDirectorio>> recordar(
    NegocioDirectorio negocio, {
    DateTime? ahora,
  }) async {
    final existentes = await leer();
    final anterior = existentes
        .where((n) => n.slug == negocio.slug)
        .firstOrNull;
    final actualizado = NegocioDirectorio(
      slug: negocio.slug,
      nombre: negocio.nombre,
      ciudad: negocio.ciudad ?? anterior?.ciudad,
      pais: negocio.pais ?? anterior?.pais,
      logoUrl: negocio.logoUrl ?? anterior?.logoUrl,
      visitadoEn: ahora ?? DateTime.now(),
    );
    final lista = [
      actualizado,
      ...existentes.where((n) => n.slug != negocio.slug),
    ].take(limite).toList();
    await _guardar(lista);
    return lista;
  }

  Future<List<NegocioDirectorio>> olvidar(String slug) async {
    final lista = (await leer()).where((n) => n.slug != slug).toList();
    await _guardar(lista);
    return lista;
  }

  Future<void> _guardar(List<NegocioDirectorio> lista) async {
    try {
      await _almacen.write(
        key: _clave,
        value: jsonEncode([for (final n in lista) n.aJson()]),
      );
    } on Object {
      // El acceso rápido es opcional.
    }
  }
}

final negociosRecientesProvider = Provider<NegociosRecientes>(
  (ref) => NegociosRecientes(),
);
