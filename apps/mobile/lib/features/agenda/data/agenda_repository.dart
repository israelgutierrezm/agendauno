import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/intento.dart';
import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/data/cuenta_models.dart';
import 'agenda_models.dart';
import 'bloqueos.dart';

/// Agenda del staff (`/app/{slug}/…`): sesiones del día, profesionales y las
/// acciones de recepción sobre una cita (llegada, inasistencia, cobro, cancelación).
class AgendaRepository {
  AgendaRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  /// Una cita por intento: reintentar la misma no agenda otra (ADR 0201).
  final _intentoCita = IntentoUnico();

  /// Agregar a alguien a una clase, una vez por intento (ADR 0201).
  final _intentoAgregar = IntentoUnico();

  String get _base => '/api/v1/app/$_slug';

  static String ymd(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<List<SesionAgenda>> sesionesDelDia(DateTime dia) => sesiones(dia, dia);

  /// Sesiones entre dos días (ambos incluidos) de la SUCURSAL de cada sesión: una
  /// clase a las 23:30 de su sucursal es de ese día aunque el teléfono esté en otra
  /// zona. A un instructor el servidor solo le da las suyas.
  Future<List<SesionAgenda>> sesiones(DateTime desde, DateTime hasta) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/sesiones',
      queryParameters: {'desde': ymd(desde), 'hasta': ymd(hasta)},
    );
    final (a, b) = (ymd(desde), ymd(hasta));
    return ((res.data?['data'] ?? []) as List)
        .map((e) => SesionAgenda.desdeJson(e as Map<String, dynamic>))
        // El servidor ensancha la ventana un día por lado (zonas): se filtran por
        // el día en la sucursal.
        .where(
          (s) =>
              ymd(s.iniciaLocal).compareTo(a) >= 0 &&
              ymd(s.iniciaLocal).compareTo(b) <= 0,
        )
        .toList()
      // Por el instante real (el orden no depende de la zona).
      ..sort((x, y) => x.iniciaEn.compareTo(y.iniciaEn));
  }

  /// El clima del Inicio del equipo (GET /clima): el de su próxima clase o cita en
  /// su sede, o el de ahora en el negocio. Null si falla o no se sabe.
  Future<ClimaMiembro?> clima() async {
    try {
      final res = await _dio.get<Map<String, dynamic>>('$_base/clima');
      final data = res.data?['data'];
      return data is Map<String, dynamic> ? ClimaMiembro.desdeJson(data) : null;
    } on DioException {
      return null;
    }
  }

  Future<List<Profesional>> profesionales() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/instructores');
    return ((res.data?['data'] ?? []) as List)
        .map((e) => Profesional.desdeJson(e as Map<String, dynamic>))
        .toList();
  }

  Future<ListaClase> roster(String sesionId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/sesiones/$sesionId/reservas',
    );
    return ListaClase.desdeJson(res.data ?? const {});
  }

  /// `retardo`: llegó tarde (solo con «presente»; cuenta como asistencia).
  Future<void> marcarAsistencia(
    String reservaId,
    String estado, {
    bool retardo = false,
  }) => _dio.post<Map<String, dynamic>>(
    '$_base/reservas/$reservaId/asistencia',
    data: {'estado': estado, 'retardo': retardo},
  );

  /// Qué pasaría si no asiste, según su política (no cambia nada).
  Future<EfectoInasistencia> efectoDeInasistencia(String reservaId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/reservas/$reservaId/inasistencia',
    );
    return EfectoInasistencia.desdeJson(
      (res.data?['data'] as Map<String, dynamic>?) ?? const {},
    );
  }

  /// Terminar de pasar lista: quien sigue sin registro «no se presentó». Devuelve cuántos.
  Future<int> terminarLista(String sesionId) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/sesiones/$sesionId/terminar-lista',
    );
    return ((res.data?['data'] as Map<String, dynamic>?)?['no_se_presentaron']
                as num? ??
            0)
        .toInt();
  }

  /// Ofrece los lugares libres de una clase a su lista de espera, en su orden.
  /// Devuelve a cuántos se les ofreció.
  Future<int> promover(String sesionId) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/sesiones/$sesionId/promover',
    );
    return ((res.data?['data'] as Map<String, dynamic>?)?['ofrecidas']
                as num? ??
            0)
        .toInt();
  }

  /// Acepta, a nombre de la persona, el lugar que se le ofreció (usa su plan).
  Future<void> aceptarLugar(String reservaId) =>
      _dio.post<Map<String, dynamic>>('$_base/reservas/$reservaId/aceptar');

  /// Agrega a la clase a quien llegó sin reservar (como en la web): se reserva con
  /// su plan; con [esperar], a la lista de espera. Reintentar lo mismo devuelve la
  /// misma reserva (clave del intento).
  Future<void> agregarAClase(
    String sesionId,
    String personaId, {
    bool esperar = false,
  }) async {
    final pedido = {
      'sesion': sesionId,
      'persona_id': personaId,
      'esperar': esperar,
    };
    try {
      await _dio.post<Map<String, dynamic>>(
        '$_base/sesiones/$sesionId/reservas',
        data: {
          'persona_id': personaId,
          'esperar': esperar,
          'idempotency_key': _intentoAgregar.clave(pedido),
        },
      );
      _intentoAgregar.listo();
    } on DioException catch (e) {
      // Con respuesta del servidor (rechazada), lo siguiente es otro intento; sin
      // ella, se reintenta con la misma clave.
      if (e.response != null) {
        _intentoAgregar.listo();
      }
      rethrow;
    }
  }

  /// Un invitado de un cliente llegó o no vino (ADR 0182).
  Future<void> marcarInvitado(String invitadoId, {required bool asistio}) =>
      _dio.put<Map<String, dynamic>>(
        '$_base/invitados/$invitadoId/asistencia',
        data: {'asistio': asistio},
      );

  /// Llegó quien reservó desde su plataforma (ADR 0202). Fitpass pide que se haya
  /// revisado su identificación oficial.
  Future<void> llegoDePlataforma(
    String lugarId, {
    bool identificacionRevisada = false,
  }) => _dio.post<Map<String, dynamic>>(
    '$_base/lugares-plataforma/$lugarId/llego',
    data: {if (identificacionRevisada) 'identificacion_revisada': true},
  );

  /// Cancela la reserva de una plataforma: le avisa y el lugar queda libre.
  Future<void> cancelarDePlataforma(String lugarId) =>
      _dio.post<Map<String, dynamic>>(
        '$_base/lugares-plataforma/$lugarId/cancelar',
        data: const <String, dynamic>{},
      );

  /// Lo que los clientes apartaron para recoger en la clase (ADR 0194).
  Future<List<ApartadoClase>> apartados(String sesionId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/sesiones/$sesionId/apartados',
    );
    return ApartadoClase.lista(res.data?['data']);
  }

  /// Cobra en caja lo que falta de la cita (el servicio o su saldo, si pagó
  /// anticipo), como en la web. La clave del intento evita cobrar dos veces si la
  /// petición se repite.
  Future<void> cobrarCita(
    String reservaId,
    String metodo, {
    required String clave,
  }) => _dio.post<Map<String, dynamic>>(
    '$_base/reservas/$reservaId/cobro',
    data: {'metodo': metodo, 'clave_intento': clave},
  );

  /// Cancelar una reserva. `por`: quién cancela; el negocio no penaliza, el
  /// cliente aplica su política (como en la web).
  Future<void> cancelarReserva(String reservaId, {String por = 'negocio'}) =>
      _dio.post<Map<String, dynamic>>(
        '$_base/reservas/$reservaId/cancelar',
        data: {'por': por},
      );

  /// Qué pasaría al cancelar ahora, según quién cancela (vista previa).
  Future<EfectoCancelacion> efectoDeCancelar(
    String reservaId, {
    String por = 'negocio',
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/reservas/$reservaId/cancelacion',
      queryParameters: {'por': por},
    );
    return EfectoCancelacion.desdeJson(
      (res.data?['data'] as Map<String, dynamic>?) ?? const {},
    );
  }

  /// Lo que ha pasado con una cita (solo negocios de citas).
  Future<List<HechoCita>> historial(String reservaId) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/reservas/$reservaId/historial',
    );
    return ((res.data?['data'] ?? []) as List)
        .map((e) => HechoCita.desdeJson(e as Map<String, dynamic>))
        .toList();
  }

  // ----------------------------- bloqueos de la agenda (fase 2.2, como la web)

  /// Los bloqueos que tocan esos días (el servidor ensancha un día por lado; la
  /// pantalla los filtra por el día en el negocio).
  Future<List<BloqueoAgenda>> bloqueos(DateTime desde, DateTime hasta) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/bloqueos',
      queryParameters: {'desde': ymd(desde), 'hasta': ymd(hasta)},
    );
    return BloqueoAgenda.lista(res.data?['data']);
  }

  /// Qué ya está agendado en el bloqueo propuesto (no guarda nada).
  Future<List<SesionAfectada>> previsualizarBloqueo(NuevoBloqueo b) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/bloqueos/previsualizar',
      data: b.aJson(),
    );
    return SesionAfectada.lista(
      (res.data?['data'] as Map<String, dynamic>?)?['afectadas'],
    );
  }

  /// Guarda el bloqueo; devuelve lo que ya estaba agendado ahí (sigue en pie).
  Future<({BloqueoAgenda? bloqueo, List<SesionAfectada> afectadas})>
  crearBloqueo(NuevoBloqueo b) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/bloqueos',
      data: b.aJson(),
    );
    final data = res.data?['data'];
    return (
      bloqueo:
          data is Map<String, dynamic> &&
              data['desde'] is String &&
              data['hasta'] is String
          ? BloqueoAgenda.desdeJson(data)
          : null,
      afectadas: SesionAfectada.lista(res.data?['afectadas']),
    );
  }

  /// Quita un bloqueo: ese horario se puede volver a agendar.
  Future<void> quitarBloqueo(String bloqueoId) =>
      _dio.delete<void>('$_base/bloqueos/$bloqueoId');

  // ----------------------------- agendar y reprogramar (igual que la web)

  /// Los servicios que se ofrecen (sin los archivados), para agendar una cita.
  Future<List<ServicioCita>> serviciosParaCita() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/ofertas');
    return ((res.data?['data'] ?? []) as List)
        .map((e) => ServicioCita.desdeJson(e as Map<String, dynamic>))
        .toList();
  }

  /// Las sucursales del negocio (dónde será la cita).
  Future<List<SucursalAgenda>> sucursales() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/sucursales');
    return ((res.data?['data'] ?? []) as List)
        .map((e) => SucursalAgenda.desdeJson(e as Map<String, dynamic>))
        .toList();
  }

  /// Los huecos libres de un profesional en una sucursal ese día (su horario de
  /// atención menos lo ocupado, con la duración y los márgenes del servicio). Cada
  /// uno trae su hora en la sucursal (`inicia_local`).
  Future<List<HorarioCita>> horariosLibres({
    required String instructorId,
    required String sucursalId,
    required DateTime fecha,
    String? ofertaId,
    int? duracionMinutos,
    String? excluirReserva,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/disponibilidad',
      queryParameters: {
        'instructor_id': instructorId,
        'sucursal_id': sucursalId,
        'fecha': ymd(fecha),
        'oferta_id': ?ofertaId,
        'duracion_minutos': ?duracionMinutos,
        // Al mover una cita, su propio horario no cuenta como ocupado.
        'excluir_reserva': ?excluirReserva,
      },
    );
    return HorarioCita.deLista(
      (res.data?['data'] as Map<String, dynamic>?)?['slots'],
    );
  }

  /// Busca clientes en el servidor por nombre, correo o celular (los primeros 8),
  /// como el buscador de la web.
  Future<List<ClienteAgenda>> buscarClientes(String texto) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/miembros',
      queryParameters: {
        'tipo': 'miembro',
        'q': texto,
        'page': 1,
        'per_page': 8,
      },
    );
    return ((res.data?['data'] ?? []) as List)
        .map((e) => ClienteAgenda.desdeJson(e as Map<String, dynamic>))
        .toList();
  }

  /// Da de alta a un cliente al vuelo (alta rápida al agendar): su nombre y, si se
  /// tienen, su celular y su correo, en la sucursal de la cita. `aceptaWhatsApp`:
  /// el cliente aceptó los avisos por WhatsApp (solo con celular, como en la web).
  Future<ClienteAgenda> crearCliente({
    required String nombre,
    String? celular,
    String? email,
    String? sucursalId,
    bool aceptaWhatsApp = false,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/miembros',
      data: {
        'nombre': nombre,
        'celular': celular,
        'email': ?email,
        if (aceptaWhatsApp) 'acepta_whatsapp': true,
        'tipo': 'miembro',
        'sucursal_id': sucursalId,
      },
    );
    return ClienteAgenda.desdeJson(
      (res.data?['data'] as Map<String, dynamic>?) ?? const {},
    );
  }

  /// El negocio agenda una cita a un cliente: queda confirmada y, si el servicio es
  /// de pago, se cobra en caja. Devuelve la cita como la muestra la agenda.
  Future<SesionAgenda> agendarCita(NuevaCita cita) async {
    final pedido = cita.aJson();
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/agenda/citas',
      data: {...pedido, 'idempotency_key': _intentoCita.clave(pedido)},
    );
    _intentoCita.listo();
    return SesionAgenda.desdeJson(
      (res.data?['data'] as Map<String, dynamic>?) ?? const {},
    );
  }

  /// Mueve una cita a otra hora (de la sucursal) y, si se quiere, con otro
  /// profesional. El servidor revalida que esté libre; si no, no cambia nada.
  Future<CitaMovida> reprogramarCita(
    String reservaId, {
    required DateTime inicia,
    String? instructorId,
  }) async {
    final res = await _dio.post<Map<String, dynamic>>(
      '$_base/reservas/$reservaId/reprogramar',
      data: {
        'inicia_en_local': horaLocalParaApi(inicia),
        'instructor_id': ?instructorId,
      },
    );
    return CitaMovida.desdeJson(
      (res.data?['data'] as Map<String, dynamic>?) ?? const {},
    );
  }
}

/// Repositorio ligado a la sesión activa (null si no hay sesión).
final agendaRepositoryProvider = Provider<AgendaRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return AgendaRepository(ref.watch(dioProvider), sesion.slug);
});
