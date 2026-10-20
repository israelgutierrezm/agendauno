import 'dart:ui';

import '../../../core/agenda/contrato_agenda.dart';
import '../../../core/formato.dart';
import '../../../core/hora_negocio.dart';
import '../../../core/theme/tema_agendauno.dart';

export '../../../core/agenda/contrato_agenda.dart';
// La vista previa de una cancelación es la misma que ve el cliente.
export '../../cuenta/data/cuenta_models.dart' show EfectoCancelacion;

/// Titular de una cita: a quién se atiende, cómo va su reserva y en qué va su
/// atención y su pago (calculados por el servidor).
class CitaTitular {
  const CitaTitular({
    required this.reservaId,
    required this.cliente,
    required this.estado,
    this.asistencia,
    this.retardo = false,
    this.ordenId,
    this.estadoAtencion,
    this.estadoPago,
    this.totalMinor,
    this.anticipoMinor = 0,
    this.porCobrarMinor,
    this.moneda,
    this.nota,
    this.asiste,
    this.telefono,
    this.email,
  });

  final String reservaId;
  final String? cliente;
  final String estado; // confirmada | pendiente_pago …
  final String? asistencia; // presente | ausente | null

  /// Llegó tarde (cuenta como que llegó).
  final bool retardo;
  final String? ordenId;

  /// En qué va: confirmada, llegó, en servicio… (null si el servidor no lo dijo).
  final EstadoCita? estadoAtencion;

  /// Por pagar en línea, por cobrar o pagada (null: no lleva cobro).
  final EstadoPagoCita? estadoPago;

  /// Lo que se cobra: el total de su orden, fijo desde que se agendó (el precio
  /// del catálogo puede cambiar después). Null sin orden.
  final int? totalMinor;

  /// Con anticipo (ADR 0161): lo que ya pagó al apartar.
  final int anticipoMinor;

  /// Lo que falta cobrar en caja (el saldo, si pagó anticipo); null si nada.
  final int? porCobrarMinor;
  final String? moneda;

  /// Lo que el cliente pidió que supiéramos al agendar (ADR 0067).
  final String? nota;

  /// Si la agendó para otra persona: quién asiste (ADR 0068).
  final String? asiste;

  /// Su contacto: solo llega a quien puede ver a los clientes.
  final String? telefono;
  final String? email;

  /// ¿Se le puede registrar la asistencia? Solo con su lugar confirmado (no con un
  /// pago en línea pendiente): el servidor rechaza lo demás.
  bool get marcable => estado == 'confirmada';

  factory CitaTitular.desdeJson(Map<String, dynamic> json) => CitaTitular(
    reservaId: json['reserva_id'] as String,
    cliente: json['cliente'] as String?,
    estado: (json['estado'] ?? 'confirmada') as String,
    asistencia: json['asistencia'] as String?,
    retardo: (json['retardo'] ?? false) as bool,
    ordenId: json['orden_id'] as String?,
    estadoAtencion: EstadoCita.desde(json['estado_atencion']),
    estadoPago: EstadoPagoCita.desde(json['estado_pago']),
    totalMinor: (json['total_minor'] as num?)?.toInt(),
    anticipoMinor: (json['anticipo_minor'] as num?)?.toInt() ?? 0,
    porCobrarMinor: (json['por_cobrar_minor'] as num?)?.toInt(),
    moneda: json['moneda'] as String?,
    nota: _texto(json['nota']),
    asiste: _texto(json['asiste']),
    telefono: _texto(json['telefono']),
    email: _texto(json['email']),
  );

  static String? _texto(Object? v) =>
      v is String && v.trim().isNotEmpty ? v.trim() : null;
}

/// Algo que pasó con una cita (GET /reservas/{id}/historial): agendada,
/// recordatorio, reprogramada, cobro, asistencia o cancelación, con quién lo hizo.
class HechoCita {
  const HechoCita({
    required this.tipo,
    required this.fecha,
    this.actor,
    this.detalle = const {},
  });

  final String tipo;
  final DateTime fecha;
  final String? actor;
  final Map<String, dynamic> detalle;

  /// Nombre de una forma de pago, como en la web.
  static String metodo(Object? m) => switch (m) {
    'efectivo' => 'Efectivo',
    'transferencia' || 'spei' => 'Transferencia',
    'manual' => 'Tarjeta (terminal)',
    'ventanilla' => 'Ventanilla',
    'tarjeta' => 'Tarjeta',
    'oxxo' => 'OXXO',
    _ => '—',
  };

  /// Qué pasó, en palabras (los mismos textos que la web).
  String get titulo {
    final d = detalle;
    return switch (tipo) {
      'agendada' => 'Cita agendada',
      'recordatorio' => 'Recordatorio enviado (${d['horas']} h antes)',
      'reprogramada' => 'Reprogramada',
      'cobrada' =>
        d['en_caja'] == true
            ? 'Cobrada en caja · ${metodo(d['metodo'])}'
            : 'Pagada en línea',
      'metodo_corregido' => 'Forma de pago corregida',
      'cobro_anulado' => 'Cobro anulado',
      'reembolsada' => 'Pago reembolsado',
      'asistencia' =>
        d['estado'] == 'ausente'
            ? 'Se marcó que no asistió'
            : 'Se marcó su llegada',
      'asistencia_corregida' =>
        d['estado'] == 'ausente'
            ? 'Asistencia corregida: no asistió'
            : 'Asistencia corregida: llegó',
      'cancelada' =>
        d['por'] == 'cliente'
            ? 'Cancelada por el cliente'
            : 'Cancelada por el negocio',
      _ => tipo,
    };
  }

  /// El detalle que acompaña al título (de qué a qué, monto, motivo), si hay.
  String? detalleEn(String moneda, String Function(int, String) dinero) {
    final d = detalle;
    String? motivo() =>
        d['motivo'] is String && (d['motivo'] as String).isNotEmpty
        ? 'Motivo: ${d['motivo']}'
        : null;
    return switch (tipo) {
      'reprogramada' when d['de'] != null && d['a'] != null =>
        'De ${d['de']} a ${d['a']}',
      'cobrada' || 'reembolsada' when d['monto_minor'] is num => dinero(
        (d['monto_minor'] as num).toInt(),
        moneda,
      ),
      'metodo_corregido' => [
        'De ${metodo(d['de'])} a ${metodo(d['a'])}',
        ?motivo(),
      ].join(' · '),
      'cobro_anulado' => motivo(),
      _ => null,
    };
  }

  factory HechoCita.desdeJson(Map<String, dynamic> json) => HechoCita(
    tipo: (json['tipo'] ?? '') as String,
    fecha: DateTime.parse(json['fecha'] as String).toLocal(),
    actor: json['actor'] as String?,
    detalle: (json['detalle'] as Map?)?.cast<String, dynamic>() ?? const {},
  );
}

/// Sesión de la agenda del staff (clase abierta o cita privada). Lo propio de cada
/// tipo va en su bloque: `clase` (cupo, lista de espera) o `cita` (a quién se
/// atiende); el del otro tipo es null.
///
/// Sus horas se MUESTRAN en la hora de la sucursal ([iniciaLocal], [terminaLocal]),
/// esté donde esté el teléfono; el instante ([iniciaEn], [terminaEn]) es para
/// ordenar y comparar con «ahora». Si el teléfono está en otra zona se avisa
/// ([etiquetaHoraSucursal]).
class SesionAgenda {
  const SesionAgenda({
    required this.id,
    required this.tipo,
    required this.oferta,
    required this.ofertaId,
    required this.instructor,
    required this.instructorId,
    required this.sala,
    this.sucursal,
    this.sucursalId,
    required this.iniciaEn,
    required this.terminaEn,
    required this.iniciaLocal,
    required this.terminaLocal,
    this.desfaseMin,
    required this.capacidad,
    required this.ocupados,
    required this.enEspera,
    required this.estado,
    this.precioMinor,
    this.clase,
    this.ocupacion,
    this.cita,
    this.marcadas = 0,
    this.asistenciaDesde,
  });

  final String id;
  final TipoSesion tipo;
  final String? oferta;
  final String? ofertaId;
  final String? instructor;
  final String? instructorId;
  final String? sala;
  final String? sucursal;

  /// La sucursal de la sesión (su ULID): con ella se buscan los horarios libres al
  /// reprogramar una cita.
  final String? sucursalId;

  /// El instante en que empieza y termina (en la zona del teléfono): para ordenar y
  /// comparar con «ahora» (si ya empezó, la ventana de asistencia). No se muestra.
  final DateTime iniciaEn;
  final DateTime terminaEn;

  /// La hora de pared de la sucursal, sin zona: sus campos (año… minuto) son el día
  /// y la hora en la sucursal. Es lo que se muestra y lo que decide a qué día
  /// pertenece la sesión. No es un instante: no se compara con «ahora» (para eso,
  /// [iniciaEn]). Sin `inicia_local` del API (versión anterior) es la hora del
  /// teléfono, como antes.
  final DateTime iniciaLocal;
  final DateTime terminaLocal;

  /// Desfase (minutos respecto a UTC) de la zona de la sucursal en ese momento
  /// (`utc_offset_min`, p. ej. -360 en la Ciudad de México); null si el API no lo
  /// manda.
  final int? desfaseMin;
  final int? capacidad;
  final int ocupados;
  final int enEspera;

  /// A cuántos de los que ocupan lugar ya se les pasó lista.
  final int marcadas;
  final String estado;

  /// Precio del servicio en el catálogo (hoy). Lo que se cobra de una cita es
  /// [montoMinor].
  final int? precioMinor;

  /// Desde cuándo se registra la asistencia (ADR 0101; null: sin límite).
  final DateTime? asistenciaDesde;

  /// Solo en clases: cupo, libres y lista de espera.
  final BloqueClase? clase;

  /// Solo en clases con cupo: la ocupación que se muestra.
  final Ocupacion? ocupacion;

  /// Solo en citas con cliente (sin titular: cancelada o aún sin cliente).
  final CitaTitular? cita;

  bool get esCita => tipo == TipoSesion.cita;
  bool get programada => estado == 'programada';
  int get duracionMin => terminaEn.difference(iniciaEn).inMinutes;

  /// La hora de pared de la sucursal de un [instante] (desde cuándo se pasa lista,
  /// un hecho del historial, «ahora»), con el desfase de la sesión. Sin desfase
  /// (API anterior), la del teléfono.
  DateTime enSucursal(DateTime instante) {
    final desfase = desfaseMin;
    return desfase == null
        ? instante.toLocal()
        : _paredDesdeDesfase(instante, desfase);
  }

  /// El día de hoy en la sucursal, según [ahora] (un instante).
  DateTime hoyEnSucursal(DateTime ahora) {
    final a = enSucursal(ahora);
    return DateTime(a.year, a.month, a.day);
  }

  /// ¿Es del día [dia] en la sucursal? (solo cuentan año, mes y día).
  bool esDelDia(DateTime dia) =>
      iniciaLocal.year == dia.year &&
      iniciaLocal.month == dia.month &&
      iniciaLocal.day == dia.day;

  /// ¿El teléfono está en otra zona que la sucursal? Se compara el desfase de la
  /// sucursal con el del teléfono en ese mismo momento ([desfaseTelefonoMin]; por
  /// omisión, el del teléfono cuando empieza la sesión): así un cambio de horario
  /// de verano entre hoy y la sesión no la marca de más. Sin desfase del API, no.
  bool enOtraZona([int? desfaseTelefonoMin]) {
    final desfase = desfaseMin;
    return desfase != null &&
        desfase !=
            (desfaseTelefonoMin ?? HoraNegocio.desfaseTelefono(iniciaEn));
  }

  /// «Hora de Roma Norte» si el teléfono está en otra zona que su sucursal; null
  /// si no (ver [etiquetaHoraSucursal]).
  String? etiquetaHora([int? desfaseTelefonoMin]) =>
      etiquetaHoraSucursal([this], desfaseTelefonoMin: desfaseTelefonoMin);

  /// Lo que cuesta: el total de la orden de la cita (fijo desde que se agendó);
  /// sin orden, el precio del catálogo.
  int? get montoMinor => cita?.totalMinor ?? precioMinor;

  /// Lo que falta cobrar en caja: el saldo si pagó anticipo (ADR 0161); si el
  /// servidor no lo dice, el total.
  int? get porCobrarMinor => cita?.porCobrarMinor ?? montoMinor;

  /// La moneda de lo que se cobra (la de su orden; si no, [respaldo]).
  String monedaDe(String respaldo) => cita?.moneda ?? respaldo;

  /// ¿Ya se puede registrar la asistencia?
  bool asistenciaAbierta(DateTime ahora) =>
      asistenciaDesde == null || !ahora.isBefore(asistenciaDesde!);

  /// Ya empezó y falta pasar lista (clase) o marcar si llegó (cita), igual que en
  /// el inicio web de quien imparte.
  bool faltaMarcar(DateTime ahora) {
    if (iniciaEn.isAfter(ahora)) {
      return false;
    }
    return switch (tipo) {
      TipoSesion.cita => cita != null && cita!.asistencia == null,
      TipoSesion.clase => ocupados > 0 && marcadas < ocupados,
    };
  }

  /// En qué va la cita, como lo calculó el servidor. Sin titular se lee por el
  /// estado de la sesión: cancelada, o aún sin cliente (null).
  EstadoCita? get estadoCita =>
      cita?.estadoAtencion ?? (programada ? null : EstadoCita.cancelada);

  /// ¿Falta cobrarla? (pendiente de pago en línea o agendada sin cobrar).
  bool get porCobrar {
    final c = cita;
    return c != null && c.ordenId != null && (c.estadoPago?.pendiente ?? false);
  }

  factory SesionAgenda.desdeJson(Map<String, dynamic> json) {
    final tipo = TipoSesion.desde(json['tipo']);
    final cita = json['cita'];
    final inicia = DateTime.parse(json['inicia_en'] as String).toLocal();
    final termina = DateTime.parse(json['termina_en'] as String).toLocal();
    // La hora de la sucursal (`inicia_local`, sin zona) y su desfase. Tolerante:
    // sin desfase se deduce de la hora local; sin hora local, se calcula con el
    // desfase; sin ninguno (API anterior), la hora del teléfono, como antes.
    final paredInicio = _horaDePared(json['inicia_local']);
    final paredFin = _horaDePared(json['termina_local']);
    final desfase =
        (json['utc_offset_min'] as num?)?.toInt() ??
        (paredInicio == null ? null : _desfaseEntre(paredInicio, inicia));
    DateTime pared(DateTime? delApi, DateTime instante) =>
        delApi ??
        (desfase == null ? instante : _paredDesdeDesfase(instante, desfase));
    return SesionAgenda(
      id: json['id'] as String,
      tipo: tipo,
      oferta: json['oferta'] as String?,
      ofertaId: json['oferta_id'] as String?,
      instructor: json['instructor'] as String?,
      instructorId: json['instructor_id'] as String?,
      sala: json['sala'] as String?,
      sucursal: json['sucursal'] as String?,
      sucursalId: json['sucursal_id'] as String?,
      iniciaEn: inicia,
      terminaEn: termina,
      iniciaLocal: pared(paredInicio, inicia),
      terminaLocal: pared(paredFin, termina),
      desfaseMin: desfase,
      capacidad: json['capacidad'] as int?,
      ocupados: (json['ocupados'] ?? 0) as int,
      enEspera: (json['en_espera'] ?? 0) as int,
      marcadas: (json['marcadas'] ?? 0) as int,
      estado: (json['estado'] ?? 'programada') as String,
      precioMinor: json['oferta_precio_clase'] as int?,
      asistenciaDesde: json['asistencia_desde'] is String
          ? DateTime.parse(json['asistencia_desde'] as String).toLocal()
          : null,
      clase: tipo == TipoSesion.clase
          ? BloqueClase.desdeJson(json['clase'], respaldo: json)
          : null,
      ocupacion: Ocupacion.desdeJson(json['ocupacion']),
      cita: tipo == TipoSesion.cita && cita is Map<String, dynamic>
          ? CitaTitular.desdeJson(cita)
          : null,
    );
  }
}

/// Lo que se avisa junto a las horas cuando el teléfono está en otra zona que la
/// sucursal (las horas son siempre las de la sucursal): «Hora de Roma Norte»,
/// «Hora de la sucursal» si no se sabe cuál o «Hora de cada sucursal» si son
/// varias. Null si todas están en la zona del teléfono (o el API no manda el
/// desfase). [desfaseTelefonoMin] fija el del teléfono (pruebas); si no, se toma el
/// del teléfono en el momento de cada sesión.
String? etiquetaHoraSucursal(
  Iterable<SesionAgenda> sesiones, {
  int? desfaseTelefonoMin,
}) => HoraNegocio.etiqueta(
  sesiones
      .where((s) => s.enOtraZona(desfaseTelefonoMin))
      .map((s) => s.sucursal),
);

final _formatoPared = RegExp(
  r'^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?',
);

/// `AAAA-MM-DDTHH:MM[:SS]` (sin zona; si trae una, se ignora) como hora de pared,
/// o null si no vino o no se entiende.
DateTime? _horaDePared(Object? valor) {
  final m = valor is String ? _formatoPared.firstMatch(valor.trim()) : null;
  if (m == null) {
    return null;
  }
  int n(int i) => int.parse(m.group(i) ?? '0');
  final (mes, dia, hora, minuto, segundo) = (n(2), n(3), n(4), n(5), n(6));
  if (mes < 1 ||
      mes > 12 ||
      dia < 1 ||
      dia > 31 ||
      hora > 23 ||
      minuto > 59 ||
      segundo > 59) {
    return null;
  }
  return DateTime(n(1), mes, dia, hora, minuto, segundo);
}

/// La hora de pared de [instante] en una zona con ese desfase (minutos).
DateTime _paredDesdeDesfase(DateTime instante, int desfaseMin) =>
    HoraNegocio.enDesfase(instante, desfaseMin);

/// El desfase (minutos) de la zona en la que [instante] se ve como [pared].
int _desfaseEntre(DateTime pared, DateTime instante) =>
    HoraNegocio.desfaseEntre(pared, instante);

/// Profesional/instructor (columna de la agenda de citas).
class Profesional {
  const Profesional({required this.id, required this.nombre});

  final String id;
  final String nombre;

  String get iniciales {
    final partes = nombre
        .trim()
        .split(RegExp(r'\s+'))
        .where((p) => p.isNotEmpty)
        .take(2);
    return partes.map((p) => p[0].toUpperCase()).join();
  }

  factory Profesional.desdeJson(Map<String, dynamic> json) => Profesional(
    id: json['id'] as String,
    nombre: (json['nombre'] ?? '') as String,
  );
}

// ------------------------------------- agendar y reprogramar desde el equipo

/// Un servicio del catálogo para agendar una cita (GET /ofertas, solo los que se
/// ofrecen): lo que dura y cómo se habilita (se cobra en caja o usa un crédito).
class ServicioCita {
  const ServicioCita({
    required this.id,
    required this.nombre,
    this.precioMinor,
    this.politicaReserva = 'entitlement',
    this.duracionMinutos,
  });

  final String id;
  final String nombre;
  final int? precioMinor;

  /// `pago` (se cobra en caja) o `entitlement` (usa un crédito de su plan).
  final String politicaReserva;

  /// La que fija el servicio (null: se elige al agendar).
  final int? duracionMinutos;

  bool get seCobraEnCaja => politicaReserva == 'pago';

  /// "Corte · 30 min · $150.00", como en el selector de la web.
  String etiqueta(String moneda) => [
    nombre,
    if (duracionMinutos != null) '$duracionMinutos min',
    if (seCobraEnCaja && (precioMinor ?? 0) > 0)
      Formato.dinero(precioMinor!, moneda),
  ].join(' · ');

  /// Lo que pasa al agendarla (los mismos textos que la web).
  String aviso(String moneda) => seCobraEnCaja
      ? 'Queda confirmada y se cobra en caja: '
            '${Formato.dinero(precioMinor ?? 0, moneda)}.'
      : 'Queda confirmada y usa un crédito de su membresía.';

  factory ServicioCita.desdeJson(Map<String, dynamic> j) => ServicioCita(
    id: j['id'] as String,
    nombre: (j['nombre'] ?? '') as String,
    precioMinor: (j['precio_clase_minor'] as num?)?.toInt(),
    politicaReserva: (j['politica_reserva'] ?? 'entitlement') as String,
    duracionMinutos: switch ((j['duracion_minutos'] as num?)?.toInt()) {
      final int m when m > 0 => m,
      _ => null,
    },
  );
}

/// Una sucursal del negocio (GET /sucursales): dónde será la cita.
class SucursalAgenda {
  const SucursalAgenda({required this.id, required this.nombre});

  final String id;
  final String nombre;

  factory SucursalAgenda.desdeJson(Map<String, dynamic> j) => SucursalAgenda(
    id: j['id'] as String,
    nombre: (j['nombre'] ?? '') as String,
  );
}

/// Un cliente del negocio para agendarle (GET /miembros?q=…, o el que se acaba de
/// dar de alta): su nombre y algo para distinguirlo (su correo o su celular).
class ClienteAgenda {
  const ClienteAgenda({required this.id, required this.nombre, this.detalle});

  final String id;
  final String nombre;
  final String? detalle;

  factory ClienteAgenda.desdeJson(Map<String, dynamic> j) {
    String? texto(Object? v) =>
        v is String && v.trim().isNotEmpty ? v.trim() : null;
    return ClienteAgenda(
      id: j['id'] as String,
      nombre: texto(j['nombre_completo']) ?? texto(j['nombre']) ?? '—',
      detalle: texto(j['email']) ?? texto(j['celular']),
    );
  }
}

/// Lo que se manda para agendar una cita a un cliente (POST /agenda/citas), igual
/// que la web: la hora es la de la sucursal, sin zona (`inicia_en_local`).
class NuevaCita {
  const NuevaCita({
    required this.personaId,
    required this.ofertaId,
    required this.sucursalId,
    required this.instructorId,
    required this.inicia,
    required this.duracionMinutos,
  });

  final String personaId;
  final String ofertaId;
  final String sucursalId;
  final String instructorId;

  /// La hora de pared de la sucursal (ver [horaLocalParaApi]).
  final DateTime inicia;
  final int duracionMinutos;

  Map<String, dynamic> aJson() => {
    'persona_id': personaId,
    'oferta_id': ofertaId,
    'sucursal_id': sucursalId,
    'instructor_id': instructorId,
    'inicia_en_local': horaLocalParaApi(inicia),
    'duracion_minutos': duracionMinutos,
  };
}

/// Una hora de pared de la sucursal como la pide el API en `inicia_en_local`
/// ("AAAA-MM-DD HH:MM:00", sin zona): el servidor la lee en la zona de la sucursal.
String horaLocalParaApi(DateTime pared) =>
    '${Formato.iso(pared)} ${Formato.hora(pared)}:00';

/// La hora de pared de la sucursal de un horario libre (su `inicia_local`, sin
/// zona), o null si no vino. Se guarda en UTC solo para que el horario de verano
/// del teléfono no la mueva: sus campos son el día y la hora en la sucursal.
DateTime? paredDeHorario(String? iniciaLocal) {
  final m = iniciaLocal == null
      ? null
      : RegExp(
          r'^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})',
        ).firstMatch(iniciaLocal.trim());
  if (m == null) {
    return null;
  }
  int n(int i) => int.parse(m.group(i)!);
  return DateTime.utc(n(1), n(2), n(3), n(4), n(5));
}

/// Lo que respondió el servidor al mover una cita (POST /reservas/{id}/reprogramar):
/// el horario de antes y el nuevo (instantes).
class CitaMovida {
  const CitaMovida({this.antes, this.ahora, this.sesionId});

  final DateTime? antes;
  final DateTime? ahora;
  final String? sesionId;

  factory CitaMovida.desdeJson(Map<String, dynamic> j) => CitaMovida(
    antes: DateTime.tryParse((j['antes'] ?? '') as String),
    ahora: DateTime.tryParse((j['ahora'] ?? '') as String),
    sesionId: j['sesion_id'] as String?,
  );
}

/// Asistente en la lista de una clase (roster) para el pase de lista.
class Asistente {
  const Asistente({
    required this.reservaId,
    required this.nombre,
    required this.estado,
    this.asistencia,
    this.retardo = false,
    this.automatica = false,
    this.primeraVez = false,
    this.adeudo = false,
  });

  final String reservaId;
  final String nombre;
  final String estado; // confirmada | ofrecida | en_espera | pendiente_pago
  final String? asistencia; // presente | ausente | null

  /// Llegó tarde (cuenta como que llegó) y si la marcó el sistema al terminar.
  final bool retardo;
  final bool automatica;
  final bool primeraVez;
  final bool adeudo;

  bool get enSala => estado != 'en_espera';
  bool get llego => asistencia == 'presente';

  /// Solo se pasa lista a quien tiene su lugar confirmado (no a una oferta de
  /// lugar sin aceptar ni a un pago pendiente): el servidor rechaza lo demás.
  bool get marcable => estado == 'confirmada';

  factory Asistente.desdeJson(Map<String, dynamic> json) => Asistente(
    reservaId: json['id'] as String,
    nombre: (json['persona'] ?? '—') as String,
    estado: (json['estado'] ?? 'confirmada') as String,
    asistencia: json['asistencia'] as String?,
    retardo: (json['retardo'] ?? false) as bool,
    automatica: (json['asistencia_automatica'] ?? false) as bool,
    primeraVez: (json['primera_vez'] ?? false) as bool,
    adeudo: (json['adeudo'] ?? false) as bool,
  );
}

/// La lista de una clase (GET /sesiones/{id}/reservas): quién reservó y desde cuándo
/// se pasa lista (ADR 0101). `empezo` lo dice el servidor (no el reloj del teléfono).
class ListaClase {
  const ListaClase({
    required this.asistentes,
    this.asistenciaDesde,
    this.empezo = false,
  });

  final List<Asistente> asistentes;
  final DateTime? asistenciaDesde;
  final bool empezo;

  /// ¿Ya se puede registrar la asistencia?
  bool abierta(DateTime ahora) =>
      asistenciaDesde == null || !ahora.isBefore(asistenciaDesde!);

  factory ListaClase.desdeJson(Map<String, dynamic> json) {
    final meta = (json['meta'] as Map<String, dynamic>?) ?? const {};
    final desde = meta['asistencia_desde'] as String?;
    return ListaClase(
      asistentes: ((json['data'] ?? []) as List)
          .map((e) => Asistente.desdeJson(e as Map<String, dynamic>))
          .toList(),
      asistenciaDesde: desde == null ? null : DateTime.parse(desde).toLocal(),
      empezo: (meta['empezo'] ?? false) as bool,
    );
  }
}

/// Colores de servicio (fondo claro + tinta del mismo matiz), como en la web.
class TonoServicio {
  const TonoServicio(this.fondo, this.tinta);

  final Color fondo;
  final Color tinta;

  static const paleta = [
    TonoServicio(Color(0xFFDCE9FF), Color(0xFF0B3A8C)),
    TonoServicio(Color(0xFFD6F2EA), Color(0xFF0A5A47)),
    TonoServicio(Color(0xFFFCE0EB), Color(0xFF8A1747)),
    TonoServicio(Color(0xFFEBE3FF), Color(0xFF4A2A8F)),
    TonoServicio(Color(0xFFFDE7D6), Color(0xFF7A3708)),
    TonoServicio(Color(0xFFFFF1CC), Color(0xFF6B4700)),
    TonoServicio(Color(0xFFDFF3FB), Color(0xFF0B5470)),
    TonoServicio(Color(0xFFE6F4D7), Color(0xFF3B5A12)),
  ];

  /// En el modo del teléfono: en oscuro, la tinta se aclara y el fondo queda suave
  /// sobre la superficie.
  TonoServicio en(ColoresAgendaUno c) {
    final (f, t) = c.par(fondo, tinta);
    return TonoServicio(f, t);
  }

  /// Tono estable por servicio (hash del id).
  static TonoServicio de(String? ofertaId) {
    var h = 0;
    for (final c in (ofertaId ?? '').codeUnits) {
      h = (h * 31 + c) & 0x7fffffff;
    }
    return paleta[h % paleta.length];
  }
}

/// Qué pasa si no asiste, como lo calcula el API con su política
/// (`GET /reservas/{id}/inasistencia`): el crédito que pierde o no, o, sin
/// crédito, lo ya pagado que el negocio conserva (el anticipo, ADR 0161).
class EfectoInasistencia {
  const EfectoInasistencia({
    required this.conCredito,
    required this.tolerada,
    required this.pierdeCredito,
    required this.unidades,
    this.conserva,
    this.conservaMinor,
    this.moneda,
  });

  final bool conCredito;
  final bool tolerada;
  final bool pierdeCredito;

  /// 1000 unidades = 1 crédito.
  final int unidades;

  /// Sin crédito: `anticipo` o `pago`, lo ya pagado que no se devuelve; null si
  /// no pagó nada.
  final String? conserva;
  final int? conservaMinor;
  final String? moneda;

  factory EfectoInasistencia.desdeJson(Map<String, dynamic> j) =>
      EfectoInasistencia(
        conCredito: (j['con_credito'] ?? false) as bool,
        tolerada: (j['tolerada'] ?? false) as bool,
        pierdeCredito: (j['pierde_credito'] ?? false) as bool,
        unidades: (j['unidades'] as num?)?.toInt() ?? 0,
        conserva: j['conserva'] as String?,
        conservaMinor: (j['conserva_minor'] as num?)?.toInt(),
        moneda: j['moneda'] as String?,
      );

  /// El efecto en una frase, con las mismas palabras que la web
  /// (`confirmaciones.asistencia.efecto`).
  String get texto {
    if (!conCredito) {
      final monto = conservaMinor ?? 0;
      if (conserva != null && monto > 0 && moneda != null) {
        final dinero = Formato.dinero(monto, moneda!);
        return conserva == 'anticipo'
            ? 'El anticipo de $dinero no se devuelve: el negocio lo conserva.'
            : 'Ya pagó $dinero: no se devuelve por no asistir.';
      }
      return 'No usó créditos en esta reserva: su saldo no cambia.';
    }
    if (pierdeCredito) {
      if (unidades == 1000) {
        return 'Pierde 1 crédito de su plan.';
      }
      final n = unidades / 1000;
      final cifra = n == n.truncateToDouble()
          ? n.toInt().toString()
          : n.toStringAsFixed(1);
      return 'Pierde $cifra créditos de su plan.';
    }
    return tolerada
        ? 'Esta falta entra en la tolerancia de su política: no pierde el crédito.'
        : 'Su política no cobra las faltas: el crédito vuelve a su saldo.';
  }
}

/// Colores sólidos por profesional (avatar y chip).
const coloresProfesional = [
  Color(0xFF2563EB),
  Color(0xFF0B8468),
  Color(0xFFC2410C),
  Color(0xFF7C3AED),
  Color(0xFFDB2777),
  Color(0xFF0E7490),
  Color(0xFF4D7C0F),
  Color(0xFFB45309),
];
