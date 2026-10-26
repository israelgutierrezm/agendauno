import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/intento.dart';
import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import 'ventas_models.dart';

/// Cobrar y vender desde la app del equipo, con las rutas y los permisos de la web:
/// la lista de lo pendiente (`ordenes.ver`), cobrarlo en caja y vender un plan
/// (`ordenes.gestionar`, en la caja de la sede). La venta lleva la clave de su
/// intento: repetirla (sin red, otro toque) no cobra dos veces. El cobro de una
/// orden pendiente es el de la web (`/liquidar`, con el precio de contado del
/// negocio, ADR 0191): una orden ya pagada no se vuelve a cobrar.
class VentasRepository {
  VentasRepository(this._dio, this._slug, {this.moneda = 'MXN'});

  final Dio _dio;
  final String _slug;

  /// La del negocio, para lo que no traiga la suya.
  final String moneda;

  final _intentoVenta = IntentoUnico();

  String get _base => '/api/v1/app/$_slug';

  static const porPagina = 20;

  /// Lo pendiente de cobro (de sus sedes), lo más nuevo primero.
  Future<PaginaOrdenes> pendientes({int pagina = 1}) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/ordenes',
      queryParameters: {
        'estado': 'pendiente',
        'page': pagina,
        'per_page': porPagina,
      },
    );
    final meta = (res.data?['meta'] as Map<String, dynamic>?) ?? const {};
    return PaginaOrdenes(
      ordenes: ((res.data?['data'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map((o) => OrdenEquipo.desdeJson(o, moneda: moneda))
          .where((o) => o.pendiente)
          .toList(),
      pagina: (meta['page'] as num? ?? pagina).toInt(),
      ultimaPagina: (meta['ultima_pagina'] as num? ?? pagina).toInt(),
      total: (meta['total'] as num? ?? 0).toInt(),
    );
  }

  /// Registra en caja el pago de una orden pendiente, como la web
  /// (`POST /ordenes/{id}/liquidar`, en la caja de [sucursalId]): todo en efectivo o
  /// por transferencia aplica el precio de contado del negocio. Devuelve la orden
  /// como quedó (con lo que se cobró).
  Future<OrdenEquipo> cobrar(
    String ordenId, {
    required String forma,
    String? sucursalId,
    String? referencia,
  }) async {
    final limpia = referencia?.trim();
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/ordenes/$ordenId/liquidar',
      data: {
        'metodo': forma,
        'referencia': limpia == null || limpia.isEmpty ? null : limpia,
        'sucursal_id': ?sucursalId,
      },
    );
    final orden = res.data?['data'];
    return OrdenEquipo.desdeJson(
      orden is Map<String, dynamic>
          ? orden
          : {'id': ordenId, 'estado': 'pagada'},
      moneda: moneda,
    );
  }

  /// Los planes a la venta (sin los archivados).
  Future<List<PlanVenta>> planes() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/productos');
    return ((res.data?['data'] ?? const []) as List)
        .whereType<Map<String, dynamic>>()
        .map((p) => PlanVenta.desdeJson(p, moneda: moneda))
        .where((p) => !p.archivado && p.id.isNotEmpty)
        .toList();
  }

  /// La inscripción que se le sumaría a esa persona (centavos; 0 si no le toca).
  Future<int> inscripcion(String personaId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/miembros/$personaId/cuotas',
    );
    final data = res.data?['data'];
    return data is Map<String, dynamic>
        ? (data['inscripcion_minor'] as num? ?? 0).toInt()
        : 0;
  }

  /// Vende un plan y lo cobra en caja de una vez (`POST /ordenes` con `liquidar`):
  /// si el cobro no pasa (la caja está cerrada, p. ej.), no queda nada por cobrar.
  /// Devuelve la orden.
  Future<OrdenEquipo> vender({
    required String personaId,
    required String planId,
    required String forma,
    String? sucursalId,
    bool aceptaCondiciones = false,
  }) async {
    final pedido = {
      'comprador_id': personaId,
      'items': [
        {'producto_id': planId, 'cantidad': 1},
      ],
      if (aceptaCondiciones) 'acepta_condiciones': true,
    };
    final firma = {...pedido, 'forma': forma, 'sucursal_id': sucursalId};
    final res = await _conIntento(
      _intentoVenta,
      firma,
      (clave) => _dio.post<Map<String, dynamic>>(
        '$_base/ordenes',
        data: {
          ...pedido,
          'liquidar': {
            'metodo': forma,
            'sucursal_id': ?sucursalId,
            'clave': clave,
          },
        },
      ),
    );
    return OrdenEquipo.desdeJson(
      (res.data?['data'] as Map<String, dynamic>?) ?? const {},
      moneda: moneda,
    );
  }

  /// Manda con la clave del intento. Con respuesta del servidor (hecho o
  /// rechazado), lo siguiente es otro intento; sin ella (sin red), se reintenta con
  /// la misma clave.
  Future<Response<Map<String, dynamic>>> _conIntento(
    IntentoUnico intento,
    Object firma,
    Future<Response<Map<String, dynamic>>> Function(String clave) enviar,
  ) async {
    try {
      final res = await enviar(intento.clave(firma));
      intento.listo();
      return res;
    } on DioException catch (e) {
      if (e.response != null) {
        intento.listo();
      }
      rethrow;
    }
  }
}

/// ¿Puede cobrar y vender? Con el permiso de las rutas (como la web).
bool puedeCobrarOrdenes(Sesion? sesion) =>
    (sesion?.puede('ordenes.ver') ?? false) &&
    (sesion?.puede('ordenes.gestionar') ?? false);

bool puedeVenderPlanes(Sesion? sesion) =>
    (sesion?.puede('ordenes.gestionar') ?? false) &&
    (sesion?.puede('productos.ver') ?? false);

/// Repositorio ligado a la sesión activa (null si no hay sesión).
final ventasRepositoryProvider = Provider<VentasRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return VentasRepository(
    ref.watch(dioProvider),
    sesion.slug,
    moneda: sesion.moneda,
  );
});
