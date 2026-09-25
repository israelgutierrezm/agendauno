import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import 'cuenta_models.dart';

/// Acceso a los datos del autoservicio del miembro (`/app/{slug}/mi/*`), sobre el
/// estudio de la sesion activa.
class CuentaRepository {
  CuentaRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  String get _base => '/api/v1/app/$_slug';

  Future<MiCuenta> cargar({required bool conClases}) async {
    final respuestas = await Future.wait([
      _dio.get<Map<String, dynamic>>('$_base/mi/perfil'),
      _dio.get<Map<String, dynamic>>('$_base/mi/waivers'),
      if (conClases) _dio.get<Map<String, dynamic>>('$_base/mi/agenda'),
    ]);

    final data = (respuestas[0].data?['data'] ?? {}) as Map<String, dynamic>;
    final derechos = ((data['derechos'] ?? []) as List)
        .map((e) => DerechoMiembro.desdeJson(e as Map<String, dynamic>))
        .toList();
    final reservas = ((data['reservas'] ?? []) as List)
        .map((e) => ReservaMiembro.desdeJson(e as Map<String, dynamic>))
        .toList();
    final consentimientos = ((respuestas[1].data?['data'] ?? []) as List)
        .map(
          (e) => ConsentimientoPendiente.desdeJson(e as Map<String, dynamic>),
        )
        .toList();
    final clases = conClases
        ? ((respuestas[2].data?['data'] ?? []) as List)
              .map((e) => ClaseMiembro.desdeJson(e as Map<String, dynamic>))
              .toList()
        : <ClaseMiembro>[];

    return MiCuenta(
      derechos: derechos,
      reservas: reservas,
      clases: clases,
      consentimientos: consentimientos,
      pagoEnLinea: (data['pago_en_linea'] ?? false) as bool,
      pagoAutomatico: (data['pago_automatico'] ?? false) as bool,
      resenasPendientes: await _resenasPendientes(),
      porPagar: await _porPagar(),
    );
  }

  /// Lo que tiene pendiente de pago; si falla, simplemente no se muestra.
  Future<List<OrdenPorPagar>> _porPagar() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('$_base/mi/ordenes');
      return OrdenPorPagar.pendientes((res.data?['data'] ?? []) as List);
    } on DioException {
      return const [];
    }
  }

  /// Lo que puede calificar; si falla, simplemente no se ofrece.
  Future<List<ResenaPendiente>> _resenasPendientes() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '$_base/mi/resenas/pendientes',
      );
      return ((res.data?['data'] ?? []) as List)
          .map((e) => ResenaPendiente.desdeJson(e as Map<String, dynamic>))
          .toList();
    } on DioException {
      return const [];
    }
  }

  /// Califica una clase o cita a la que asistió (1 a 5 y comentario opcional).
  Future<void> calificar(
    String reservaId,
    int calificacion,
    String? comentario,
  ) => _dio.post<Map<String, dynamic>>(
    '$_base/mi/reservas/$reservaId/resena',
    data: {'calificacion': calificacion, 'comentario': comentario},
  );

  Future<Privacidad> privacidad() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/mi/privacidad');
    return Privacidad.desdeJson(
      (res.data?['data'] ?? {}) as Map<String, dynamic>,
    );
  }

  /// Recibir o no promociones del negocio (oposición).
  Future<Privacidad> cambiarPromociones(bool recibir) async {
    final res = await _dio.put<Map<String, dynamic>>(
      '$_base/mi/privacidad',
      data: {'recibe_promociones': recibir},
    );
    return Privacidad.desdeJson(
      (res.data?['data'] ?? {}) as Map<String, dynamic>,
    );
  }

  /// Todo lo que el negocio tiene de él (derecho de acceso).
  Future<Map<String, dynamic>> misDatos() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/mi/datos');
    return (res.data?['data'] ?? {}) as Map<String, dynamic>;
  }

  /// Pide la baja de sus datos (cancelación); el negocio la atiende.
  Future<Privacidad> solicitarBaja(String? motivo) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/mi/privacidad/baja',
      data: {'motivo': motivo},
    );
    return Privacidad.desdeJson(
      (res.data?['data'] ?? {}) as Map<String, dynamic>,
    );
  }

  /// Sus membresías que se renuevan y cuáles se cobran solas (pago automático).
  Future<PagoAutomatico> pagoAutomatico() async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/pago-automatico',
    );
    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
    final tarjeta = data['tarjeta'];
    return PagoAutomatico(
      disponible: (data['disponible'] ?? false) as bool,
      tarjeta: tarjeta is Map<String, dynamic>
          ? TarjetaDomiciliada.desdeJson(tarjeta)
          : null,
      membresias: ((data['membresias'] ?? []) as List)
          .map((e) => MembresiaRenovable.desdeJson(e as Map<String, dynamic>))
          .toList(),
    );
  }

  /// Activa el pago automático de una membresía. Devuelve la página de la pasarela
  /// para autorizar la tarjeta, o null si ya quedó activo (ya había tarjeta).
  /// Lanza [RequiereWeb] si la pasarela captura la tarjeta con su formulario
  /// web (OpenPay.js), que no hay en la app.
  Future<String?> activarPagoAutomatico(String membresiaId) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/mi/pago-automatico/$membresiaId',
    );
    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
    if (data['estado'] == 'formulario') {
      throw const RequiereWeb();
    }
    return _urlDeCheckout(res.data);
  }

  Future<void> quitarPagoAutomatico(String membresiaId) => _dio
      .delete<Map<String, dynamic>>('$_base/mi/pago-automatico/$membresiaId');

  /// Página de la pasarela para autorizar otra tarjeta.
  Future<String?> cambiarTarjeta() async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/mi/pago-automatico/tarjeta',
    );
    return _urlDeCheckout(res.data);
  }

  String? _urlDeCheckout(Map<String, dynamic>? respuesta) {
    final checkout =
        ((respuesta?['data'] ?? {}) as Map<String, dynamic>)['checkout'];
    if (checkout is Map && checkout['tipo'] == 'redirect') {
      final url = checkout['url'];
      return url is String && url.isNotEmpty ? url : null;
    }
    return null;
  }

  /// Abre el pago en línea de una orden (p. ej. una cita apartada): devuelve la URL
  /// de la página de pago de la pasarela, o null si no hay que redirigir.
  Future<String?> pagarOrden(String ordenId) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/mi/ordenes/$ordenId/cobrar',
      data: {'metodo': 'tarjeta'},
    );
    final checkout =
        ((res.data?['data'] ?? {}) as Map<String, dynamic>)['checkout'];
    if (checkout is Map && checkout['tipo'] == 'redirect') {
      final url = checkout['url'];
      return url is String && url.isNotEmpty ? url : null;
    }
    return null;
  }

  /// Los documentos que pide el negocio y cómo va cada uno.
  Future<List<RequisitoDocumento>> misDocumentos() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/mi/documentos');
    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
    return ((data['requisitos'] ?? []) as List)
        .map((e) => RequisitoDocumento.desdeJson(e as Map<String, dynamic>))
        .toList();
  }

  /// Sube la foto del documento; queda en revisión.
  Future<void> subirDocumento(
    String tipoId,
    List<int> bytes,
    String nombreArchivo,
  ) async {
    await _dio.post<Map<String, dynamic>>(
      '$_base/mi/documentos',
      data: FormData.fromMap({
        'tipo_documento_id': tipoId,
        'archivo': MultipartFile.fromBytes(bytes, filename: nombreArchivo),
      }),
    );
  }

  /// Código del pase de entrada (QR firmado que vence en minutos).
  Future<String> pase() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/mi/pase');
    return ((res.data?['data'] ?? {}) as Map<String, dynamic>)['codigo']
            as String? ??
        '';
  }

  /// Reserva un lugar, o se anota en la lista de espera si la clase está llena.
  Future<void> reservar(String sesionId, {bool esperar = false}) =>
      _dio.post<Map<String, dynamic>>(
        '$_base/mi/reservas',
        data: {'sesion_id': sesionId, 'esperar': esperar},
      );

  Future<void> cancelar(String reservaId) =>
      _dio.post<Map<String, dynamic>>('$_base/mi/reservas/$reservaId/cancelar');

  /// Acepta el lugar que le ofreció la lista de espera.
  Future<void> aceptarLugar(String reservaId) =>
      _dio.post<Map<String, dynamic>>('$_base/mi/reservas/$reservaId/aceptar');

  Future<void> firmar(String consentimientoId) =>
      _dio.post<Map<String, dynamic>>(
        '$_base/mi/waivers/$consentimientoId/aceptar',
      );

  Future<OpcionesCita> opcionesCita() async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/citas/opciones',
    );
    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
    List<OpcionCita> lista(String clave) => ((data[clave] ?? []) as List)
        .map((e) => OpcionCita.desdeJson(e as Map<String, dynamic>))
        .toList();

    return OpcionesCita(
      servicios: lista('servicios'),
      sucursales: lista('sucursales'),
      profesionales: lista('instructores'),
    );
  }

  /// Horarios libres (inicio en ISO UTC) de un profesional en una fecha (AAAA-MM-DD).
  Future<List<String>> horariosLibres({
    required String profesionalId,
    required String sucursalId,
    required String fecha,
    required int duracionMinutos,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/citas/disponibilidad',
      queryParameters: {
        'instructor_id': profesionalId,
        'sucursal_id': sucursalId,
        'fecha': fecha,
        'duracion_minutos': duracionMinutos,
      },
    );
    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;

    return ((data['slots'] ?? []) as List)
        .map((s) => ((s as Map<String, dynamic>)['inicia'] ?? '') as String)
        .where((s) => s.isNotEmpty)
        .toList();
  }

  /// Agenda la cita; devuelve su estado (`pendiente_pago` si se paga para reservar).
  Future<String> agendarCita({
    required String servicioId,
    required String sucursalId,
    required String profesionalId,
    required String iniciaEnLocal,
    required int duracionMinutos,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/mi/citas',
      data: {
        'oferta_id': servicioId,
        'sucursal_id': sucursalId,
        'instructor_id': profesionalId,
        'inicia_en_local': iniciaEnLocal,
        'duracion_minutos': duracionMinutos,
      },
    );

    return (((res.data?['data'] ?? {}) as Map<String, dynamic>)['estado'] ?? '')
        as String;
  }
}

/// Repositorio ligado a la sesion activa (null si no hay sesion).
final cuentaRepositoryProvider = Provider<CuentaRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }

  return CuentaRepository(ref.watch(dioProvider), sesion.slug);
});

/// La acción se completa en la web (p. ej. capturar la tarjeta con OpenPay.js).
class RequiereWeb implements Exception {
  const RequiereWeb();
}
