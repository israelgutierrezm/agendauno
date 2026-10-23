import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';

/// Los datos para facturarle al cliente (el receptor del CFDI), como los guarda en
/// su cuenta (`/mi/datos-fiscales`): el negocio los usa cada vez que le factura.
class DatosFiscales {
  const DatosFiscales({
    required this.rfc,
    required this.nombre,
    required this.codigoPostal,
    required this.regimenFiscal,
    required this.usoCfdi,
    this.email,
    this.facturaAutomatica = false,
  });

  final String rfc;

  /// Nombre o razón social, tal como en la constancia.
  final String nombre;
  final String codigoPostal;
  final String regimenFiscal;
  final String usoCfdi;

  /// Dónde recibe la factura (opcional).
  final String? email;

  /// ¿Sus pagos de citas se facturan solos? (si el negocio lo ofrece).
  final bool facturaAutomatica;

  factory DatosFiscales.desdeJson(Map<String, dynamic> j) => DatosFiscales(
    rfc: (j['rfc'] ?? '') as String,
    nombre: (j['nombre'] ?? '') as String,
    codigoPostal: (j['codigo_postal'] ?? '') as String,
    regimenFiscal: (j['regimen_fiscal'] ?? '') as String,
    usoCfdi: (j['uso_cfdi'] ?? '') as String,
    email: j['email'] as String?,
    facturaAutomatica: j['factura_automatica'] == true,
  );

  /// El cuerpo del PUT: el RFC y el nombre en mayúsculas (así los guarda el
  /// servidor) y sin correo si quedó vacío.
  Map<String, dynamic> aJson() {
    final correo = email?.trim();
    return {
      'rfc': rfc.trim().toUpperCase(),
      'nombre': nombre.trim().toUpperCase(),
      'codigo_postal': codigoPostal.trim(),
      'regimen_fiscal': regimenFiscal,
      'uso_cfdi': usoCfdi,
      'email': correo == null || correo.isEmpty ? null : correo,
      'factura_automatica': facturaAutomatica,
    };
  }
}

/// Lo que responde el API: sus datos (null si aún no los guarda) y si el negocio
/// factura sus citas en automático (solo entonces se ofrece pedirlo).
class MisDatosFiscales {
  const MisDatosFiscales({this.datos, this.automaticaDisponible = false});

  final DatosFiscales? datos;
  final bool automaticaDisponible;

  factory MisDatosFiscales.desdeJson(Map<String, dynamic>? j) {
    final data = j?['data'];
    final meta = j?['meta'];
    return MisDatosFiscales(
      datos: data is Map<String, dynamic>
          ? DatosFiscales.desdeJson(data)
          : null,
      automaticaDisponible:
          meta is Map && meta['automatica_disponible'] == true,
    );
  }
}

/// El API rechazó los datos (422): el error de cada campo, para mostrarlo junto
/// a él (`rfc`, `codigo_postal`, `uso_cfdi`…).
class DatosFiscalesInvalidos implements Exception {
  const DatosFiscalesInvalidos(this.errores);

  final Map<String, String> errores;

  /// El primer texto de cada campo de `meta.errors`; vacío si no vino ninguno.
  static Map<String, String> deRespuesta(Object? data) {
    final meta = data is Map ? data['meta'] : null;
    final errores = meta is Map ? meta['errors'] : null;
    if (errores is! Map) {
      return const {};
    }
    return {
      for (final e in errores.entries)
        if (e.value is List && (e.value as List).whereType<String>().isNotEmpty)
          '${e.key}': (e.value as List).whereType<String>().first
        else if (e.value is String)
          '${e.key}': e.value as String,
    };
  }
}

/// Sus datos para facturar en la cuenta del cliente: leerlos, guardarlos y
/// borrarlos.
class DatosFiscalesRepository {
  DatosFiscalesRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  String get _ruta => '/api/v1/app/$_slug/mi/datos-fiscales';

  Future<MisDatosFiscales> cargar() async {
    final res = await _dio.get<Map<String, dynamic>>(_ruta);
    return MisDatosFiscales.desdeJson(res.data);
  }

  /// Los guarda y devuelve cómo quedaron. Un 422 con errores por campo sale como
  /// [DatosFiscalesInvalidos]; lo demás, como [DioException].
  Future<MisDatosFiscales> guardar(DatosFiscales datos) async {
    try {
      final res = await _dio.put<Map<String, dynamic>>(
        _ruta,
        data: datos.aJson(),
      );
      return MisDatosFiscales.desdeJson(res.data);
    } on DioException catch (e) {
      final errores = e.response?.statusCode == 422
          ? DatosFiscalesInvalidos.deRespuesta(e.response?.data)
          : const <String, String>{};
      if (errores.isNotEmpty) {
        throw DatosFiscalesInvalidos(errores);
      }
      rethrow;
    }
  }

  /// Los borra (ya no se le factura en automático).
  Future<void> borrar() => _dio.delete<void>(_ruta);
}

/// Repositorio ligado a la sesión activa (null si no hay sesión).
final datosFiscalesRepositoryProvider = Provider<DatosFiscalesRepository?>((
  ref,
) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return DatosFiscalesRepository(ref.watch(dioProvider), sesion.slug);
});
