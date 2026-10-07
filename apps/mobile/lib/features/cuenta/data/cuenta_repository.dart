import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import 'corte_planes.dart';
import 'cuenta_models.dart';

/// Acceso a los datos del autoservicio del miembro (`/app/{slug}/mi/*`), sobre el
/// estudio de la sesion activa.
class CuentaRepository {
  CuentaRepository(this._dio, this._slug, [this._moneda = 'MXN']);

  final Dio _dio;
  final String _slug;

  /// Moneda del negocio: la de los precios y adeudos que no traen la suya.
  final String _moneda;

  String get _base => '/api/v1/app/$_slug';

  Future<MiCuenta> cargar({required bool conClases}) async {
    final respuestas = await Future.wait([
      _dio.get<Map<String, dynamic>>('$_base/mi/perfil'),
      _dio.get<Map<String, dynamic>>('$_base/mi/waivers'),
      // Las próximas clases (para el Inicio) no tumban la cuenta si fallan: el
      // calendario de Reservas pide su periodo aparte y muestra su propio error.
      if (conClases)
        _dio
            .get<Map<String, dynamic>>('$_base/mi/agenda')
            .catchError(
              (Object _) => Response<Map<String, dynamic>>(
                requestOptions: RequestOptions(path: '$_base/mi/agenda'),
                data: const {'data': <dynamic>[]},
              ),
              test: (e) => e is DioException,
            ),
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
      portal: PortalCliente.desdeJson(data['portal']),
      asistencias30Dias: (data['asistencias_30_dias'] as num? ?? 0).toInt(),
    );
  }

  /// Lo que puede comprar (los planes vigentes del negocio, del más barato).
  Future<List<ProductoComprable>> productos() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/mi/productos');
    return ((res.data?['data'] ?? []) as List)
        .whereType<Map<String, dynamic>>()
        .map((p) => ProductoComprable.desdeJson(p, moneda: _moneda))
        .toList();
  }

  /// Compra un plan: crea la orden pendiente (se activa al pagarla).
  Future<void> comprar(String productoId) => _dio.post<Map<String, dynamic>>(
    '$_base/mi/ordenes',
    data: {
      'items': [
        {'producto_id': productoId, 'cantidad': 1},
      ],
    },
  );

  /// Las clases del periodo que ve el calendario (fechas locales, fin incluido)
  /// y, si eligió una, de esa sucursal.
  Future<AgendaPeriodo> agendaPeriodo(
    DateTime desde,
    DateTime hasta, {
    String? sucursalId,
  }) async {
    String ymd(DateTime d) =>
        '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/agenda',
      queryParameters: {
        'desde': ymd(desde),
        'hasta': ymd(hasta),
        if (sucursalId != null && sucursalId.isNotEmpty)
          'sucursal_id': sucursalId,
      },
    );
    return AgendaPeriodo.desdeJson(res.data ?? const {});
  }

  /// El clima de su Inicio: el pronóstico para su próxima clase o cita en su
  /// sucursal o el de ahora. Si falla o no se sabe, null (no se muestra).
  Future<ClimaMiembro?> clima() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('$_base/mi/clima');
      final data = res.data?['data'];
      return data is Map<String, dynamic> ? ClimaMiembro.desdeJson(data) : null;
    } on DioException {
      return null;
    }
  }

  /// Todo lo que debe (completo, aparte del historial: un adeudo antiguo no se
  /// pierde); si falla, simplemente no se muestra.
  Future<List<OrdenPorPagar>> _porPagar() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        '$_base/mi/ordenes/pendientes',
      );
      return OrdenPorPagar.pendientes(
        (res.data?['data'] ?? []) as List,
        moneda: _moneda,
      );
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

  /// Una página de su historial de clases y citas (lo más reciente primero).
  Future<PaginaHistorial> historial(int pagina) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/historial',
      queryParameters: {'page': pagina, 'per_page': 15},
    );
    return PaginaHistorial.desdeJson(res.data ?? const {});
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

  /// Aceptar o retirar los avisos por WhatsApp.
  Future<Privacidad> cambiarWhatsapp(bool acepta) async {
    final res = await _dio.put<Map<String, dynamic>>(
      '$_base/mi/privacidad',
      data: {'acepta_whatsapp': acepta},
    );
    return Privacidad.desdeJson(
      (res.data?['data'] ?? {}) as Map<String, dynamic>,
    );
  }

  /// Todo lo que el negocio tiene de él (derecho de acceso). Se confirma con su
  /// contraseña (va en el cuerpo, nunca en la URL).
  Future<Map<String, dynamic>> misDatos(String password) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/mi/datos',
      data: {'password': password},
    );
    return (res.data?['data'] ?? {}) as Map<String, dynamic>;
  }

  /// Pide la baja de sus datos (cancelación), confirmando con su contraseña; el
  /// negocio la atiende.
  Future<Privacidad> solicitarBaja(String? motivo, String password) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/mi/privacidad/baja',
      data: {'motivo': motivo, 'password': password},
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
          .map(
            (e) => MembresiaRenovable.desdeJson(
              e as Map<String, dynamic>,
              moneda: _moneda,
            ),
          )
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

  /// A qué puede cambiar su reserva (cita: horarios libres de ese día).
  Future<OpcionesReprogramar> opcionesReprogramar(
    String reservaId, {
    String? fecha,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/reservas/$reservaId/reprogramar',
      queryParameters: {'fecha': ?fecha},
    );
    return OpcionesReprogramar.desdeJson(
      (res.data?['data'] ?? {}) as Map<String, dynamic>,
    );
  }

  /// Cambia el horario: cita a `iniciaEnLocal` (AAAA-MM-DD HH:MM:SS) o clase a
  /// otra fecha (`sesionId`).
  Future<void> reprogramar(
    String reservaId, {
    String? iniciaEnLocal,
    String? sesionId,
  }) => _dio.post<Map<String, dynamic>>(
    '$_base/mi/reservas/$reservaId/reprogramar',
    data: {'inicia_en_local': ?iniciaEnLocal, 'sesion_id': ?sesionId},
  );

  /// Qué pasará con su crédito si cancela ahora.
  Future<EfectoCancelacion> efectoDeCancelar(String reservaId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/reservas/$reservaId/cancelacion',
    );
    return EfectoCancelacion.desdeJson(
      (res.data?['data'] ?? {}) as Map<String, dynamic>,
    );
  }

  /// Corte de sus planes: qué incluía cada uno, cómo lo usó y lo que le queda.
  Future<List<PlanCorte>> planes() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/mi/planes');
    return ((res.data?['data'] ?? []) as List)
        .map((e) => PlanCorte.desdeJson(e as Map<String, dynamic>))
        .toList();
  }

  /// Movimientos de créditos de uno de sus planes (los más recientes primero).
  Future<List<MovimientoCredito>> movimientos(String derechoId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/derechos/$derechoId/movimientos',
    );
    return ((res.data?['data'] ?? []) as List)
        .map((e) => MovimientoCredito.desdeJson(e as Map<String, dynamic>))
        .toList();
  }

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
  /// Con el servicio, el negocio aplica su duración y su preparación/limpieza.
  /// Días (AAAA-MM-DD) desde [desde] en que se puede agendar en la sede: alguien
  /// atiende (o [profesionalId]), el negocio no cerró y no pasaron (ADR 0065).
  Future<Set<String>> diasConAtencion({
    required String sucursalId,
    required String desde,
    int dias = 62,
    String? profesionalId,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/citas/dias',
      queryParameters: {
        'sucursal_id': sucursalId,
        'desde': desde,
        'dias': dias,
        'instructor_id': ?profesionalId,
      },
    );

    return ((res.data?['data'] ?? const []) as List)
        .cast<Map<String, dynamic>>()
        .where((d) => d['abierto'] == true)
        .map((d) => (d['fecha'] ?? '') as String)
        .toSet();
  }

  /// Sin [profesionalId] («cualquier profesional») salen los huecos en que alguien
  /// del equipo está libre.
  Future<List<HorarioCita>> horariosLibres({
    required String? profesionalId,
    required String sucursalId,
    required String fecha,
    required int duracionMinutos,
    String? servicioId,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/mi/citas/disponibilidad',
      queryParameters: {
        'instructor_id': ?profesionalId,
        'sucursal_id': sucursalId,
        'fecha': fecha,
        'duracion_minutos': duracionMinutos,
        'oferta_id': ?servicioId,
      },
    );
    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;

    return HorarioCita.deLista(data['slots']);
  }

  /// Agenda la cita; devuelve su estado (`pendiente_pago` si se paga para reservar)
  /// y quién la atenderá (sin [profesionalId], el negocio asigna a quien esté libre).
  Future<({String estado, String? profesional})> agendarCita({
    required String servicioId,
    required String sucursalId,
    required String? profesionalId,
    required String iniciaEnLocal,
    required int duracionMinutos,
    String? nota,
    bool aceptaWhatsapp = false,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/mi/citas',
      data: {
        'oferta_id': servicioId,
        'sucursal_id': sucursalId,
        'instructor_id': ?profesionalId,
        'inicia_en_local': iniciaEnLocal,
        'duracion_minutos': duracionMinutos,
        // Lo que el cliente quiere que sepa el negocio (ADR 0067).
        'nota': ?nota,
        // Pidió los avisos por WhatsApp al agendar (ADR 0069).
        if (aceptaWhatsapp) 'acepta_whatsapp': true,
      },
    );

    final data = (res.data?['data'] ?? {}) as Map<String, dynamic>;
    final profesional = data['profesional'] as Map<String, dynamic>?;

    return (
      estado: (data['estado'] ?? '') as String,
      profesional: profesional?['nombre'] as String?,
    );
  }
}

/// Repositorio ligado a la sesion activa (null si no hay sesion).
final cuentaRepositoryProvider = Provider<CuentaRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }

  return CuentaRepository(ref.watch(dioProvider), sesion.slug, sesion.moneda);
});

/// La acción se completa en la web (p. ej. capturar la tarjeta con OpenPay.js).
class RequiereWeb implements Exception {
  const RequiereWeb();
}
