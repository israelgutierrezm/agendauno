import 'package:flutter/material.dart';

import '../../../core/agenda/contrato_agenda.dart';
import '../../../core/formato.dart';
import '../../../core/hora_negocio.dart';
import '../../../core/theme/tema_agendauno.dart';

export '../../../core/agenda/contrato_agenda.dart';

/// En qué sucursales vale un plan (`todas_sucursales` y `sucursales` del API, en
/// /mi/productos, /mi/perfil y /mi/planes). Sin el dato, en todas.
class CoberturaSucursales {
  const CoberturaSucursales({this.todas = true, this.sucursales = const []});

  final bool todas;

  /// Los nombres de sus sucursales, si no vale en todas.
  final List<String> sucursales;

  /// "Solo en Roma Norte, Condesa"; null si vale en todas (no se dice nada).
  String? get texto =>
      todas || sucursales.isEmpty ? null : 'Solo en ${sucursales.join(', ')}';

  factory CoberturaSucursales.desdeJson(Map<String, dynamic> j) {
    final sucursales = j['sucursales'];
    return CoberturaSucursales(
      todas: j['todas_sucursales'] != false,
      sucursales: sucursales is List
          ? sucursales
                .whereType<Map<String, dynamic>>()
                .map((s) => (s['nombre'] ?? '') as String)
                .where((n) => n.isNotEmpty)
                .toList()
          : const [],
    );
  }
}

/// Modelos del autoservicio del miembro (Mi cuenta).
class DerechoMiembro {
  const DerechoMiembro({
    required this.ilimitado,
    this.id,
    this.saldo,
    this.disponible,
    this.producto,
    this.pausaHasta,
    this.vence,
    this.desde,
    this.estado = 'vigente',
    this.cobertura = const CoberturaSucursales(),
  });

  final bool ilimitado;
  final String? id;
  final int? saldo;
  final int? disponible;
  final String? producto;

  /// En qué sucursales vale.
  final CoberturaSucursales cobertura;

  /// Último día en pausa (AAAA-MM-DD) si la membresía está congelada.
  final String? pausaHasta;

  /// Hasta cuándo se puede usar (AAAA-MM-DD); null si no vence.
  final String? vence;

  /// Desde cuándo se puede usar (AAAA-MM-DD); null si ya se puede.
  final String? desde;

  /// Estado efectivo que da el servidor: vigente, agotado, por_empezar,
  /// pausado, suspendido, vencido o cancelado (no se deduce por fechas).
  final String estado;

  /// Se puede usar hoy (aunque se le hayan acabado las clases).
  bool get vigente => estado == 'vigente' || estado == 'agotado';

  /// "Ilimitado", "1 crédito", "0.5 créditos" o "Sin paquete activo": lo que
  /// tiene disponible entre todos sus planes, sumado en unidades (1000 = 1
  /// crédito) y sin redondear: con medio crédito no se le promete una clase.
  static String resumenCreditos(List<DerechoMiembro> derechos) {
    if (derechos.isEmpty) {
      return 'Sin paquete activo';
    }
    if (derechos.any((d) => d.ilimitado)) {
      return 'Ilimitado';
    }
    final unidades = derechos.fold<int>(
      0,
      (a, d) => a + ((d.disponible ?? 0) > 0 ? d.disponible! : 0),
    );
    return unidades == 1000
        ? '1 crédito'
        : '${Formato.creditos(unidades)} créditos';
  }

  factory DerechoMiembro.desdeJson(Map<String, dynamic> j) => DerechoMiembro(
    ilimitado: (j['ilimitado'] ?? false) as bool,
    id: j['id'] as String?,
    saldo: j['saldo'] as int?,
    disponible: j['disponible'] as int?,
    producto: j['producto'] as String?,
    pausaHasta: j['pausa_hasta'] as String?,
    vence: j['vence'] as String?,
    desde: j['desde'] as String?,
    estado: (j['estado'] ?? 'vigente') as String,
    cobertura: CoberturaSucursales.desdeJson(j),
  );
}

/// Un horario libre para agendar o mover una cita. `inicia` es el instante (ISO
/// UTC); `iniciaLocal` (`AAAA-MM-DDTHH:MM`) es la hora en la zona de la sede, la
/// que se muestra y la que el API espera en `inicia_en_local`: así no importa en
/// qué zona esté el teléfono (una sede en Cancún vista desde la Ciudad de México).
/// Si el API no la manda, se usa la hora del teléfono, como antes.
class HorarioCita {
  const HorarioCita({required this.inicia, this.iniciaLocal});

  final String inicia;
  final String? iniciaLocal;

  factory HorarioCita.desdeJson(Map<String, dynamic> j) => HorarioCita(
    inicia: (j['inicia'] ?? '') as String,
    iniciaLocal: j['inicia_local'] as String?,
  );

  /// Los horarios de una lista de `slots` del API (sin los que no traen inicio).
  static List<HorarioCita> deLista(Object? slots) => ((slots ?? []) as List)
      .map((s) => HorarioCita.desdeJson(s as Map<String, dynamic>))
      .where((h) => h.inicia.isNotEmpty)
      .toList();

  static final _formatoLocal = RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}');

  /// `AAAA-MM-DDTHH:MM` de la sede, o null si no vino (o no se entiende).
  String? get _local {
    final local = iniciaLocal;
    return local != null && _formatoLocal.hasMatch(local)
        ? local.substring(0, 16)
        : null;
  }

  /// La hora del teléfono (solo cuando el API no manda la de la sede).
  DateTime get _delTelefono => DateTime.parse(inicia).toLocal();

  /// "09:30" en la hora de la sede.
  String get hora => _local?.substring(11) ?? Formato.hora(_delTelefono);

  /// Lo que se manda como `inicia_en_local` (`AAAA-MM-DDTHH:MM`, hora de la sede).
  String get iniciaEnLocal =>
      _local ?? '${Formato.iso(_delTelefono)}T${Formato.hora(_delTelefono)}';

  /// ¿La sede está en otra zona que el teléfono? (se avisa junto a las horas).
  bool get enOtraZona {
    final local = _local;
    final instante = DateTime.tryParse(inicia);
    if (local == null || instante == null) {
      return false;
    }
    final telefono = instante.toLocal();
    return local != '${Formato.iso(telefono)}T${Formato.hora(telefono)}';
  }
}

/// A qué puede cambiar su reserva (ADR 0044): horarios libres de la cita o
/// otras fechas de la clase. Si no puede, el motivo.
class OpcionesReprogramar {
  const OpcionesReprogramar({
    required this.puede,
    required this.tipo,
    required this.restantes,
    this.motivo,
    this.horarios = const [],
    this.sesiones = const [],
  });

  final bool puede;
  final String? motivo;

  /// El de la sesión: decide si se elige otro horario o otra fecha.
  final TipoSesion tipo;
  final int restantes;

  /// Cita: cada horario libre (en la hora de la sede).
  final List<HorarioCita> horarios;

  /// Clase: cada fecha con lugar: su id, su inicio (ISO) y, si el API la manda, la
  /// hora en su sede (`AAAA-MM-DDTHH:MM`, sin zona).
  final List<({String id, String iniciaEn, String? iniciaLocal})> sesiones;

  factory OpcionesReprogramar.desdeJson(Map<String, dynamic> j) =>
      OpcionesReprogramar(
        puede: (j['puede'] ?? false) as bool,
        motivo: j['motivo'] as String?,
        // Siempre viene; uno desconocido es un error (no se supone cita).
        tipo: TipoSesion.desde(j['tipo']),
        restantes: (j['restantes'] ?? 0) as int,
        horarios: HorarioCita.deLista(j['slots']),
        sesiones: ((j['sesiones'] ?? []) as List).map((s) {
          final m = s as Map<String, dynamic>;
          return (
            id: (m['id'] ?? '') as String,
            iniciaEn: (m['inicia_en'] ?? '') as String,
            iniciaLocal: m['inicia_local'] as String?,
          );
        }).toList(),
      );
}

/// Qué pasará con su crédito si cancela ahora (vista previa del negocio).
class EfectoCancelacion {
  const EfectoCancelacion({required this.cancelable, required this.mensaje});

  final bool cancelable;
  final String mensaje;

  factory EfectoCancelacion.desdeJson(Map<String, dynamic> j) =>
      EfectoCancelacion(
        cancelable: (j['cancelable'] ?? false) as bool,
        mensaje: (j['mensaje'] ?? '') as String,
      );
}

/// Un movimiento de sus créditos: por qué cambió el saldo.
class MovimientoCredito {
  const MovimientoCredito({
    required this.id,
    required this.concepto,
    required this.unidades,
    required this.saldoPosterior,
    this.fecha,
    this.clase,
    this.claseIniciaEn,
    this.claseDesfaseMin,
  });

  final String id;
  final String concepto;
  final int unidades;
  final int saldoPosterior;
  final String? fecha;
  final String? clase;
  final String? claseIniciaEn;

  /// El desfase con UTC de la sede de la clase (`utc_offset_min`); null si el
  /// API no lo manda (se usa el del negocio).
  final int? claseDesfaseMin;

  /// "+8", "-1", "-0.5" (1 crédito = 1000 unidades).
  static String creditos(int unidades, {bool conSigno = true}) {
    final texto = Formato.creditos(unidades);
    return conSigno && unidades > 0 ? '+$texto' : texto;
  }

  factory MovimientoCredito.desdeJson(Map<String, dynamic> j) {
    final clase = j['clase'] as Map<String, dynamic>?;
    return MovimientoCredito(
      id: (j['id'] ?? '') as String,
      concepto: (j['concepto'] ?? '') as String,
      unidades: (j['unidades'] ?? 0) as int,
      saldoPosterior: (j['saldo_posterior'] ?? 0) as int,
      fecha: j['fecha'] as String?,
      clase: clase?['nombre'] as String?,
      claseIniciaEn: clase?['inicia_en'] as String?,
      claseDesfaseMin: (clase?['utc_offset_min'] as num?)?.toInt(),
    );
  }
}

/// Una reserva suya (clase o cita). Lo propio de cada tipo va en su bloque:
/// `clase` y `ocupacion` en una clase, `cita` en una cita; el otro es null.
class ReservaMiembro {
  const ReservaMiembro({
    required this.id,
    required this.estado,
    required this.tipo,
    this.sesionId,
    this.oferta,
    this.sucursal,
    this.iniciaEn,
    this.terminaEn,
    this.instructor,
    this.zonaHoraria,
    this.desfaseMin,
    this.ofertaExpiraEn,
    this.ordenId,
    this.asiste,
    this.mapaUrl,
    this.clase,
    this.ocupacion,
    this.cita,
    this.lugar,
    this.lugares = 0,
    this.enlaceEnLinea,
  });

  final String id;
  final String estado;
  final String? sesionId;

  /// Clase en línea (ADR 0184): el enlace, solo con la reserva confirmada.
  final String? enlaceEnLinea;

  /// Su lugar numerado, si la clase los numera y lo eligió (B06).
  final int? lugar;

  /// Cuántos lugares numerados tiene su clase (0 = no numera): con ellos elige o
  /// cambia el suyo.
  final int lugares;

  /// Cómo llegar a la sucursal (enlace del mapa), si el negocio lo tiene.
  final String? mapaUrl;

  /// Si la agendó para otra persona: quién asiste (la cita es suya).
  final String? asiste;

  /// Clase o cita (de la sesión): se nombra por lo que es.
  final TipoSesion tipo;
  bool get esCita => tipo == TipoSesion.cita;

  /// Su clase: cupo y lista de espera (null en una cita).
  final BloqueClase? clase;

  /// La ocupación de su clase que se muestra (null en citas o sin cupo).
  final Ocupacion? ocupacion;

  /// Su cita y en qué va (null en una clase).
  final CitaReserva? cita;
  final String? oferta;
  final String? sucursal;
  final String? iniciaEn;
  final String? terminaEn;

  /// Con quién (profesional o instructor), si el negocio lo asignó.
  final String? instructor;
  final String? zonaHoraria;

  /// El desfase con UTC de su sede cuando empieza (`utc_offset_min`): sus horas
  /// se muestran en la hora de la sede. Null si el API no lo manda (se usa el del
  /// negocio).
  final int? desfaseMin;
  // Hasta cuándo puede aceptar el lugar que le ofreció la lista de espera.
  final String? ofertaExpiraEn;
  // Orden a pagar para confirmar una cita apartada.
  final String? ordenId;

  bool get ofrecida => estado == 'ofrecida';

  String get estadoTexto => switch (estado) {
    'confirmada' => 'Confirmada',
    'en_espera' => 'En lista de espera',
    'ofrecida' => 'Lugar disponible',
    'pendiente_pago' => 'Pendiente de pago',
    _ => estado,
  };

  factory ReservaMiembro.desdeJson(Map<String, dynamic> j) {
    final tipo = TipoSesion.desde(j['tipo']);
    final cita = j['cita'];
    return ReservaMiembro(
      id: (j['id'] ?? '') as String,
      estado: (j['estado'] ?? '') as String,
      tipo: tipo,
      sesionId: j['sesion_id'] as String?,
      oferta: j['oferta'] as String?,
      sucursal: j['sucursal'] as String?,
      iniciaEn: j['inicia_en'] as String?,
      terminaEn: j['termina_en'] as String?,
      instructor: j['instructor'] as String?,
      zonaHoraria: j['zona_horaria'] as String?,
      desfaseMin: (j['utc_offset_min'] as num?)?.toInt(),
      ofertaExpiraEn: j['oferta_expira_en'] as String?,
      ordenId: j['orden_id'] as String?,
      asiste: j['asiste'] as String?,
      mapaUrl: j['mapa_url'] as String?,
      lugar: (j['lugar'] as num?)?.toInt(),
      lugares: (j['lugares'] as num? ?? 0).toInt(),
      enlaceEnLinea: j['enlace_en_linea'] as String?,
      clase: tipo == TipoSesion.clase && j['clase'] is Map<String, dynamic>
          ? BloqueClase.desdeJson(j['clase'])
          : null,
      ocupacion: Ocupacion.desdeJson(j['ocupacion']),
      cita: tipo == TipoSesion.cita && cita is Map<String, dynamic>
          ? CitaReserva.desdeJson(cita)
          : null,
    );
  }
}

/// Su cita: el estado de su reserva, si ya llegó, la orden y en qué va su
/// atención y su pago (calculados por el servidor).
class CitaReserva {
  const CitaReserva({
    required this.reservaId,
    required this.estado,
    this.asistencia,
    this.ordenId,
    this.nota,
    this.asiste,
    this.estadoAtencion,
    this.estadoPago,
    this.anticipoMinor,
    this.saldoMinor,
    this.saldoOrdenId,
  });

  final String reservaId;
  final String estado;
  final String? asistencia; // presente | ausente | null
  final String? ordenId;

  /// Con anticipo (ADR 0161): lo que ya pagó al apartar (null si no lleva anticipo
  /// o aún no lo paga), lo que queda para pagar en el negocio y la orden de ese
  /// resto mientras siga pendiente.
  final int? anticipoMinor;
  final int? saldoMinor;
  final String? saldoOrdenId;

  /// «Pagaste $X de anticipo; el resto ($Y) lo pagas en el negocio.» (null sin
  /// anticipo pagado).
  String? textoAnticipo(String moneda) =>
      anticipoMinor == null || saldoMinor == null
      ? null
      : 'Pagaste ${Formato.dinero(anticipoMinor!, moneda)} de anticipo; '
            'el resto (${Formato.dinero(saldoMinor!, moneda)}) lo pagas en el negocio.';

  /// Lo que pidió que supieran al agendar.
  final String? nota;

  /// Si la agendó para otra persona: quién asiste.
  final String? asiste;
  final EstadoCita? estadoAtencion;
  final EstadoPagoCita? estadoPago;

  factory CitaReserva.desdeJson(Map<String, dynamic> j) => CitaReserva(
    reservaId: (j['reserva_id'] ?? '') as String,
    estado: (j['estado'] ?? '') as String,
    asistencia: j['asistencia'] as String?,
    ordenId: j['orden_id'] as String?,
    nota: j['nota'] as String?,
    asiste: j['asiste'] as String?,
    estadoAtencion: EstadoCita.desde(j['estado_atencion']),
    estadoPago: EstadoPagoCita.desde(j['estado_pago']),
    anticipoMinor: (j['anticipo_minor'] as num?)?.toInt(),
    saldoMinor: (j['saldo_minor'] as num?)?.toInt(),
    saldoOrdenId: j['saldo_orden_id'] as String?,
  );
}

/// Si su plan cubre una clase, dicho por el servidor con la misma regla que al
/// reservar: incluida, solo con membresía, no incluida (y por qué) o de pago.
class CoberturaClase {
  const CoberturaClase({
    required this.estado,
    this.motivo,
    this.precioMinor,
    this.moneda = 'MXN',
  });

  final String estado;
  final String? motivo;
  final int? precioMinor;

  /// Moneda del precio (la del negocio).
  final String moneda;

  /// Se puede reservar con lo que tiene (o pagando la clase).
  bool get reservable => estado == 'incluida' || estado == 'de_pago';

  String get texto => switch (estado) {
    'incluida' => 'Incluida en tu plan',
    'solo_membresia' => 'Solo con membresía',
    'de_pago' => 'Pago por clase',
    _ => 'No incluida',
  };

  /// Por qué no la puede reservar con lo que tiene.
  String? get motivoTexto => estado == 'solo_membresia'
      ? 'Esta clase solo la incluye una membresía.'
      : switch (motivo) {
          'sin_plan' => 'Necesitas un plan para reservarla.',
          'clase' => 'Tu plan no incluye esta clase.',
          'pausa' => 'Tu plan está en pausa.',
          'suspendido' => 'Tu plan está suspendido por falta de pago.',
          'vigencia' => 'Tu plan no está vigente ese día.',
          'sucursal' => 'Tu plan no vale en esta sucursal.',
          'saldo' => 'Ya no te quedan clases en tu plan.',
          _ => null,
        };

  static CoberturaClase? desdeJson(Object? j, {String moneda = 'MXN'}) =>
      j is Map<String, dynamic>
      ? CoberturaClase(
          estado: (j['estado'] ?? 'incluida') as String,
          motivo: j['motivo'] as String?,
          precioMinor: (j['precio_minor'] as num?)?.toInt(),
          moneda: Formato.moneda(j['moneda'], moneda),
        )
      : null;
}

/// Una clase abierta de la agenda (GET /mi/agenda; en un negocio de citas viene
/// vacía).
class ClaseMiembro {
  const ClaseMiembro({
    required this.id,
    this.oferta,
    this.sucursal,
    this.iniciaEn,
    this.terminaEn,
    this.instructor,
    this.zonaHoraria,
    this.desfaseMin,
    this.capacidad,
    this.ocupados = 0,
    this.clase,
    this.ocupacion,
    this.cobertura,
  });

  final String id;
  final String? oferta;
  final String? sucursal;
  final String? iniciaEn;
  final String? terminaEn;
  final String? instructor;
  final String? zonaHoraria;

  /// El desfase con UTC de su sede cuando empieza (`utc_offset_min`); null si el
  /// API no lo manda (se usa el del negocio).
  final int? desfaseMin;
  final int? capacidad;
  final int ocupados;

  /// Cupo, lugares libres y lista de espera, como los cuenta el servidor.
  final BloqueClase? clase;

  /// La ocupación que se muestra (null sin cupo).
  final Ocupacion? ocupacion;

  /// Si su plan la cubre (null si el servidor no lo dice).
  final CoberturaClase? cobertura;

  /// Lugares libres (null sin cupo).
  int? get libres => clase?.libres;

  /// Sin lugares: se ofrece anotarse en la lista de espera.
  bool get llena => clase?.llena ?? false;

  /// Se puede reservar (sin dato de cobertura, se intenta).
  bool get reservable => cobertura?.reservable ?? true;

  /// Clase en línea (ADR 0184; `clase.en_linea`).
  bool get enLinea => clase?.enLinea ?? false;

  /// Clase en línea que se paga suelta: la app no la vende (contenido digital). Se
  /// reserva con un plan que ya la incluya.
  bool get pagoDigital =>
      enLinea && ((clase?.dePago ?? false) || cobertura?.estado == 'de_pago');

  /// Cómo queda para él, en una palabra: su cobertura o, si es en línea de pago,
  /// solo que es en línea (sin precio ni invitación a comprar).
  String? get coberturaTexto =>
      pagoDigital ? 'Clase en línea' : cobertura?.texto;

  factory ClaseMiembro.desdeJson(Map<String, dynamic> j) {
    // Aquí solo hay clases; un API anterior no mandaba el tipo.
    if (j.containsKey('tipo') &&
        TipoSesion.desde(j['tipo']) != TipoSesion.clase) {
      throw TipoSesionDesconocido(j['tipo'], esperado: TipoSesion.clase);
    }
    return ClaseMiembro(
      id: (j['id'] ?? '') as String,
      oferta: j['oferta'] as String?,
      sucursal: j['sucursal'] as String?,
      iniciaEn: j['inicia_en'] as String?,
      terminaEn: j['termina_en'] as String?,
      instructor: j['instructor'] as String?,
      zonaHoraria: j['zona_horaria'] as String?,
      desfaseMin: (j['utc_offset_min'] as num?)?.toInt(),
      capacidad: j['capacidad'] as int?,
      ocupados: (j['ocupados'] ?? 0) as int,
      clase: BloqueClase.desdeJson(j['clase'], respaldo: j),
      ocupacion: Ocupacion.desdeJson(j['ocupacion']),
      cobertura: CoberturaClase.desdeJson(j['cobertura']),
    );
  }
}

/// Consentimiento (carta responsiva, reglamento) que el negocio pide firmar.
class ConsentimientoPendiente {
  const ConsentimientoPendiente({
    required this.id,
    required this.titulo,
    required this.contenido,
    this.version = 1,
  });

  final String id;
  final String titulo;
  final String contenido;
  final int version;

  factory ConsentimientoPendiente.desdeJson(Map<String, dynamic> j) =>
      ConsentimientoPendiente(
        id: (j['id'] ?? '') as String,
        titulo: (j['titulo'] ?? '') as String,
        contenido: (j['contenido'] ?? '') as String,
        version: (j['version'] ?? 1) as int,
      );
}

/// Opciones para agendar una cita desde la cuenta.
class OpcionCita {
  const OpcionCita({
    required this.id,
    required this.nombre,
    this.duracionMinutos,
    this.zonaHoraria,
    this.incluye = const [],
    this.fotoUrl,
    this.conPlan = false,
    this.sucursales,
    this.region,
    this.direccion,
    this.mapaUrl,
    this.precioMinor,
    this.moneda = 'MXN',
    this.anticipoMinor,
    this.profesionales,
  });

  final String id;
  final String nombre;

  /// Servicio: su precio y la moneda del negocio.
  final int? precioMinor;
  final String moneda;

  /// Lo que se paga en línea para apartar; el resto, en el negocio (ADR 0161). null
  /// si no pide anticipo o el negocio no cobra en línea.
  final int? anticipoMinor;

  /// El anticipo que se ofrece al agendar: no en lo que se toma con su bono, y solo
  /// si deja algo por pagar en el negocio (si no, se paga completo).
  int? get anticipoAlAgendar {
    final anticipo = anticipoMinor;
    final precio = precioMinor;
    return conPlan ||
            anticipo == null ||
            precio == null ||
            anticipo <= 0 ||
            anticipo >= precio
        ? null
        : anticipo;
  }

  /// Sede: zona, dirección y cómo llegar (M06), para saber dónde será la cita.
  final String? region;
  final String? direccion;
  final String? mapaUrl;

  /// «Roma Norte · Av. Álvaro Obregón 123» (lo que haya).
  String? get ubicacion {
    final partes = [
      region,
      direccion,
    ].whereType<String>().where((p) => p.trim().isNotEmpty).toList();
    return partes.isEmpty ? null : partes.join(' · ');
  }

  final int? duracionMinutos;
  final String? zonaHoraria;

  /// Foto pública del profesional (para elegirlo por su foto al agendar).
  final String? fotoUrl;

  /// Paquete: los servicios que incluye, en orden (vacío en un servicio simple).
  final List<String> incluye;

  /// Se toma con su bono o membresía: no se paga al agendar (ADR 0091).
  final bool conPlan;

  /// Profesional: las sedes (ids) donde atiende, las de su horario. null si el API
  /// no lo dice (atiende en todas, como en la web).
  final List<String>? sucursales;

  /// ¿Atiende en esa sede? (sin sede elegida, sí).
  bool atiendeEn(String? sucursalId) =>
      sucursales == null ||
      sucursalId == null ||
      sucursales!.contains(sucursalId);

  /// Servicio: quiénes lo dan (ids, ADR 0217). null si lo da cualquiera.
  final List<String>? profesionales;

  /// ¿Ese profesional da este servicio?
  bool loDa(String profesionalId) =>
      profesionales == null || profesionales!.contains(profesionalId);

  factory OpcionCita.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) => OpcionCita(
    id: (j['id'] ?? '') as String,
    nombre: (j['nombre'] ?? '') as String,
    duracionMinutos: j['duracion_minutos'] as int?,
    zonaHoraria: j['zona_horaria'] as String?,
    incluye: ((j['incluye'] ?? const []) as List).whereType<String>().toList(),
    fotoUrl: j['foto_url'] as String?,
    conPlan: j['con_plan'] == true,
    sucursales: j['sucursales'] is List
        ? (j['sucursales'] as List).whereType<String>().toList()
        : null,
    region: j['region'] as String?,
    direccion: j['direccion'] as String?,
    mapaUrl: j['mapa_url'] as String?,
    precioMinor: (j['precio_minor'] as num?)?.toInt(),
    moneda: Formato.moneda(j['moneda'], moneda),
    anticipoMinor: (j['anticipo_minor'] as num?)?.toInt(),
    profesionales: j['profesionales'] is List
        ? (j['profesionales'] as List).whereType<String>().toList()
        : null,
  );
}

class OpcionesCita {
  const OpcionesCita({
    required this.servicios,
    required this.sucursales,
    required this.profesionales,
  });

  final List<OpcionCita> servicios;
  final List<OpcionCita> sucursales;
  final List<OpcionCita> profesionales;

  /// Solo quienes atienden en la sede elegida: con alguien de otra sede no habría
  /// horarios (como en la web). Con un servicio, solo quienes lo dan (ADR 0217).
  List<OpcionCita> profesionalesEn(
    String? sucursalId, {
    OpcionCita? servicio,
  }) => profesionales
      .where((p) => p.atiendeEn(sucursalId) && (servicio?.loDa(p.id) ?? true))
      .toList();
}

/// Lo que el alumno tiene por pagar: p. ej. la renovación de su membresía, que se
/// abre unos días antes para pagarla por adelantado. Una cita apartada se paga
/// desde su reserva.
class OrdenPorPagar {
  const OrdenPorPagar({
    required this.id,
    required this.concepto,
    required this.totalMinor,
    this.moneda = 'MXN',
    this.detalle,
    this.recurrente = false,
    this.pagoEnProceso,
    this.estado = 'pendiente',
    this.fecha,
    this.digital = false,
  });

  final String id;
  final String concepto;
  final int totalMinor;

  /// Es de contenido digital (una clase en línea o videos; `digital` del API): la
  /// app no lo cobra (reglas 3.1.1 y 3.1.3 de Apple).
  final bool digital;

  /// Lo que se dice en lugar de «Pagar» en una orden digital: un hecho, sin decir
  /// dónde pagarla.
  static const noSePagaAqui = 'Este pago no se hace desde la app.';

  /// La de la orden (la del negocio si no la trae).
  final String moneda;

  /// Si es una cita: con quién, cuándo y dónde.
  final String? detalle;

  /// Se renueva (membresía o su renovación): se puede pagar dejando el pago
  /// automático.
  final bool recurrente;

  /// Ya inició un pago en línea que espera confirmación: con qué medio (`oxxo`,
  /// `tarjeta`…; vacío si no se sabe). Null si no hay ninguno (M07).
  final String? pagoEnProceso;

  /// `pendiente`, `pagada` o `cancelada` (en el historial).
  final String estado;

  /// Cuándo se pagó (o, si no, cuándo se creó), para el historial: en la hora del
  /// negocio.
  final DateTime? fecha;

  /// Para no pagar dos veces lo que ya se está pagando.
  String? get avisoEnProceso => pagoEnProceso == null
      ? null
      : pagoEnProceso == 'oxxo'
      ? 'Ya pediste tu ficha para pagar en OXXO. Si ya pagaste, la confirmación '
            'puede tardar hasta un día.'
      : 'Ya iniciaste un pago en línea. Si ya pagaste, espera la confirmación '
            'antes de volver a pagar.';

  /// Cómo va, en el historial.
  String get estadoTexto => switch (estado) {
    'pagada' => 'Pagada',
    'cancelada' => 'Cancelada',
    _ => 'Pendiente de pago',
  };

  /// Todo lo que debe (GET /mi/ordenes/pendientes, completo): también las citas.
  static List<OrdenPorPagar> pendientes(
    List<dynamic> ordenes, {
    String moneda = 'MXN',
    int? desfaseMin,
  }) => ordenes
      .whereType<Map<String, dynamic>>()
      .where((o) => o['estado'] == 'pendiente')
      .map(
        (o) =>
            OrdenPorPagar.desdeJson(o, moneda: moneda, desfaseMin: desfaseMin),
      )
      .toList();

  /// [desfaseMin]: el del negocio (el de la sesión): las fechas van en su hora;
  /// la de la cita, en la de su sede si el API la manda (`utc_offset_min`).
  factory OrdenPorPagar.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
    int? desfaseMin,
  }) {
    final lineas = ((j['lineas'] ?? []) as List)
        .whereType<Map<String, dynamic>>()
        .map((l) {
          final nombre = (l['producto'] as String?) ?? 'Producto';
          final cantidad = (l['cantidad'] as int?) ?? 1;
          // Para una persona a su cargo (B05): se dice para quién.
          final para = l['para'];
          return [
            cantidad > 1 ? '$nombre × $cantidad' : nombre,
            if (para is String && para.isNotEmpty) '(para $para)',
          ].join(' ');
        })
        .toList();
    final sesion = j['sesion'];
    final conPara = lineas.any((l) => l.contains('(para '));
    final concepto = conPara
        ? lineas.join(', ')
        : (j['concepto'] as String?) ?? lineas.join(', ');
    final enProceso = j['pago_en_proceso'];
    final fecha = (j['pagada_en'] ?? j['fecha']) as String?;
    return OrdenPorPagar(
      id: j['id'] as String,
      concepto: concepto.isEmpty ? '—' : concepto,
      totalMinor: (j['total_minor'] as int?) ?? 0,
      moneda: Formato.moneda(j['moneda'], moneda),
      detalle: sesion is Map<String, dynamic>
          ? [
              if (sesion['profesional'] != null) 'Con ${sesion['profesional']}',
              if (sesion['inicia_en'] != null)
                Formato.fechaHora(
                  sesion['inicia_en'] as String,
                  desfaseMin:
                      (sesion['utc_offset_min'] as num?)?.toInt() ?? desfaseMin,
                ),
              if (sesion['sucursal'] != null) sesion['sucursal'] as String,
            ].join(' · ')
          : null,
      recurrente: (j['recurrente'] ?? false) as bool,
      pagoEnProceso: enProceso is Map
          ? ((enProceso['metodo'] as String?) ?? '')
          : null,
      estado: (j['estado'] as String?) ?? 'pendiente',
      fecha: HoraNegocio.pared(fecha, desfaseMin: desfaseMin),
      digital: j['digital'] == true,
    );
  }
}

/// Una página del historial de compras (GET /mi/ordenes, sin lo pendiente).
class PaginaCompras {
  const PaginaCompras({
    required this.ordenes,
    required this.pagina,
    required this.ultimaPagina,
  });

  final List<OrdenPorPagar> ordenes;
  final int pagina;
  final int ultimaPagina;

  bool get hayMas => pagina < ultimaPagina;
}

/// Cómo sigue un pago en línea (POST /mi/ordenes/{id}/cobrar): la página de la
/// pasarela, una ficha para pagar en tienda o esperar la confirmación.
sealed class CheckoutPago {
  const CheckoutPago();

  factory CheckoutPago.desdeJson(Object? checkout) {
    if (checkout is Map && checkout['tipo'] == 'redirect') {
      final url = checkout['url'];
      if (url is String && url.isNotEmpty) {
        return IrAPagarEnLinea(url);
      }
    }
    if (checkout is Map && checkout['tipo'] == 'voucher') {
      return FichaDePago(
        referencia: (checkout['referencia'] ?? '') as String,
        codigoBarras: checkout['codigo_barras'] as String?,
        recibo: checkout['recibo'] as String?,
      );
    }
    return const PagoPorConfirmar();
  }
}

/// Se paga en la página de la pasarela.
class IrAPagarEnLinea extends CheckoutPago {
  const IrAPagarEnLinea(this.url);

  final String url;
}

/// Se paga en tienda (OXXO) con esta referencia; se confirma solo.
class FichaDePago extends CheckoutPago {
  const FichaDePago({required this.referencia, this.codigoBarras, this.recibo});

  final String referencia;

  /// Imagen del código de barras y recibo para imprimir (URLs de la pasarela).
  final String? codigoBarras;
  final String? recibo;
}

/// El pago quedó en proceso: se confirma cuando la pasarela lo apruebe.
class PagoPorConfirmar extends CheckoutPago {
  const PagoPorConfirmar();
}

/// Estado agregado de la pantalla Mi cuenta.
class MiCuenta {
  const MiCuenta({
    required this.derechos,
    required this.reservas,
    required this.clases,
    this.consentimientos = const [],
    this.pagoEnLinea = false,
    this.pagoEnLineaPrueba = false,
    this.pagoAutomatico = false,
    this.domiciliarAlPagar = false,
    this.resenasPendientes = const [],
    this.porPagar = const [],
    this.porPagarSinCargar = false,
    this.portal,
    this.asistencias30Dias = 0,
    this.clasesMes,
    this.rachaSemanas = 0,
  });

  /// La pasarela del negocio está en pruebas: por ahora no se cobra de verdad (M07).
  final bool pagoEnLineaPrueba;

  /// Qué partes de su cuenta le sirven; null si el API no lo dice (ADR 0091).
  final PortalCliente? portal;

  /// A cuántas clases o citas llegó en los últimos 30 días.
  final int asistencias30Dias;

  /// Su resumen (ADR 0182): clases a las que vino este mes (null si el API no lo
  /// manda) y cuántas semanas seguidas lleva viniendo.
  final int? clasesMes;
  final int rachaSemanas;

  /// Lo que tiene pendiente de pago (p. ej. su renovación).
  final List<OrdenPorPagar> porPagar;

  /// No se pudo cargar lo pendiente: no se sabe si está al corriente.
  final bool porPagarSinCargar;

  /// Clases o citas a las que asistió y aún no califica.
  final List<ResenaPendiente> resenasPendientes;

  /// ¿El negocio cobra en línea? Entonces puede pagar aquí lo pendiente.
  final bool pagoEnLinea;

  /// ¿La pasarela admite pago automático (domiciliar sus membresías)?
  final bool pagoAutomatico;

  /// ¿Se domicilia al pagar (la casilla del pago)? Solo con Stripe; con Mercado
  /// Pago u OpenPay el pago automático se activa aparte.
  final bool domiciliarAlPagar;
  final List<DerechoMiembro> derechos;
  final List<ReservaMiembro> reservas;
  final List<ClaseMiembro> clases;
  final List<ConsentimientoPendiente> consentimientos;

  /// Las clases que ya reservó (o en las que espera), para no ofrecerlas de nuevo.
  Set<String> get sesionesReservadas =>
      reservas.map((r) => r.sesionId).whereType<String>().toSet();
}

/// Qué partes de su cuenta le sirven al cliente (ADR 0091): sus créditos, el pase
/// y el expediente aparecen solo cuando el negocio de verdad los usa.
class PortalCliente {
  const PortalCliente({
    required this.creditos,
    required this.pase,
    required this.expediente,
  });

  final bool creditos;
  final bool pase;
  final bool expediente;

  static PortalCliente? desdeJson(Object? j) => j is Map<String, dynamic>
      ? PortalCliente(
          creditos: j['creditos'] == true,
          pase: j['pase'] == true,
          expediente: j['expediente'] == true,
        )
      : null;
}

/// Documento que pide el negocio y cómo va el del alumno.
class RequisitoDocumento {
  const RequisitoDocumento({
    required this.tipoId,
    required this.nombre,
    this.obligatorio = false,
    this.estado,
    this.motivo,
    this.documento,
  });

  final String tipoId;
  final String nombre;
  final bool obligatorio;

  /// null (falta) | pendiente | aprobado | rechazado.
  final String? estado;
  final String? motivo;

  /// Lo último que subió para este requisito (se puede ver); null si falta.
  final DocumentoMio? documento;

  String get estadoTexto => switch (estado) {
    'pendiente' => 'En revisión',
    'aprobado' => 'Aprobado',
    'rechazado' => motivo != null ? 'Rechazado · $motivo' : 'Rechazado',
    _ => 'Falta subirlo',
  };

  Color colorEn(ColoresAgendaUno c) => switch (estado) {
    'pendiente' => c.aviso,
    'aprobado' => c.exito,
    'rechazado' => c.error,
    _ => c.textoSuave,
  };

  factory RequisitoDocumento.desdeJson(Map<String, dynamic> j) {
    final tipo = (j['tipo'] ?? {}) as Map<String, dynamic>;
    final documento = j['documento'] as Map<String, dynamic>?;
    return RequisitoDocumento(
      tipoId: (tipo['id'] ?? '') as String,
      nombre: (tipo['nombre'] ?? '') as String,
      obligatorio: (tipo['obligatorio'] ?? false) as bool,
      estado: documento?['estado'] as String?,
      motivo: documento?['motivo'] as String?,
      documento: documento == null ? null : DocumentoMio.desdeJson(documento),
    );
  }
}

/// Un documento que subió (`GET /mi/documentos/{id}` lo descarga con su sesión).
class DocumentoMio {
  const DocumentoMio({required this.id, required this.nombre, this.estado});

  final String id;

  /// El nombre del archivo como lo subió (de ahí su tipo si el servidor no lo dice).
  final String nombre;

  /// pendiente | aprobado | rechazado.
  final String? estado;

  String get estadoTexto => switch (estado) {
    'pendiente' => 'En revisión',
    'aprobado' => 'Aprobado',
    'rechazado' => 'Rechazado',
    _ => '',
  };

  factory DocumentoMio.desdeJson(Map<String, dynamic> j) => DocumentoMio(
    id: (j['id'] ?? '') as String,
    nombre: (j['nombre'] ?? '') as String,
    estado: j['estado'] as String?,
  );
}

/// Tarjeta con la que se cobran los pagos automáticos (nunca el número completo).
class TarjetaDomiciliada {
  const TarjetaDomiciliada({this.marca, this.ultimos4, this.expira});

  final String? marca;
  final String? ultimos4;
  final String? expira;

  String get texto {
    final m = marca == null || marca!.isEmpty
        ? 'Tarjeta'
        : '${marca![0].toUpperCase()}${marca!.substring(1)}';
    return '$m terminación ${ultimos4 ?? '····'}';
  }

  factory TarjetaDomiciliada.desdeJson(Map<String, dynamic> j) =>
      TarjetaDomiciliada(
        marca: j['marca'] as String?,
        ultimos4: j['ultimos4'] as String?,
        expira: j['expira'] as String?,
      );
}

/// Membresía que se renueva: si se cobra sola (pago automático) y el último rechazo.
class MembresiaRenovable {
  const MembresiaRenovable({
    required this.id,
    required this.automatico,
    this.pendiente = false,
    this.producto,
    this.montoMinor,
    this.moneda = 'MXN',
    this.proximaCobroEn,
    this.error,
    this.tarjeta,
    this.para,
    this.cancelando = false,
    this.precioNuevoMinor,
  });

  final String id;
  final bool automatico;

  /// Se quitó y la pasarela aún no confirma que dejó de cobrar (ADR 0134).
  final bool cancelando;

  /// El precio del plan cambió: el nuevo. Hasta autorizarlo se cobra [montoMinor]
  /// (el que autorizó).
  final int? precioNuevoMinor;

  /// De una persona a su cargo (su nombre): se cobra a la tarjeta del responsable
  /// (ADR 0129).
  final String? para;

  /// Suscripción creada en la pasarela que falta autorizar.
  final bool pendiente;

  /// Con qué tarjeta se cobra (en suscripciones, una por membresía).
  final TarjetaDomiciliada? tarjeta;
  final String? producto;
  final int? montoMinor;

  /// La del plan (la del negocio si no la trae).
  final String moneda;
  final String? proximaCobroEn;
  final String? error;

  factory MembresiaRenovable.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) => MembresiaRenovable(
    id: (j['id'] ?? '') as String,
    automatico: (j['automatico'] ?? false) as bool,
    pendiente: (j['pendiente'] ?? false) as bool,
    cancelando: (j['cancelando'] ?? false) as bool,
    precioNuevoMinor: (j['precio_nuevo_minor'] as num?)?.toInt(),
    tarjeta: j['tarjeta'] is Map<String, dynamic>
        ? TarjetaDomiciliada.desdeJson(j['tarjeta'] as Map<String, dynamic>)
        : null,
    producto: j['producto'] as String?,
    montoMinor: j['monto_minor'] as int?,
    moneda: Formato.moneda(j['moneda'], moneda),
    proximaCobroEn: j['proxima_cobro_en'] as String?,
    error: j['error'] as String?,
    para: j['para'] is Map<String, dynamic>
        ? (j['para'] as Map<String, dynamic>)['nombre'] as String?
        : null,
  );
}

class PagoAutomatico {
  const PagoAutomatico({
    required this.disponible,
    required this.membresias,
    this.tarjeta,
  });

  final bool disponible;
  final TarjetaDomiciliada? tarjeta;
  final List<MembresiaRenovable> membresias;
}

/// Una clase o cita a la que asistió y puede calificar (1 a 5 y un comentario).
class ResenaPendiente {
  const ResenaPendiente({
    required this.reservaId,
    this.actividad,
    this.con,
    this.fecha,
    this.desfaseMin,
  });

  final String reservaId;
  final String? actividad;
  final String? con;
  final String? fecha;

  /// El desfase con UTC de su sede (`utc_offset_min`); null si el API no lo
  /// manda (se usa el del negocio).
  final int? desfaseMin;

  factory ResenaPendiente.desdeJson(Map<String, dynamic> j) => ResenaPendiente(
    reservaId: (j['reserva_id'] ?? '') as String,
    actividad: j['actividad'] as String?,
    con: j['con'] as String?,
    fecha: j['fecha'] as String?,
    desfaseMin: (j['utc_offset_min'] as num?)?.toInt(),
  );
}

/// Privacidad del alumno frente al negocio (derechos ARCO).
class Privacidad {
  const Privacidad({
    required this.recibePromociones,
    this.whatsappDisponible = false,
    this.aceptaWhatsapp = false,
    this.whatsappConCelular = false,
    this.baja,
  });

  final bool recibePromociones;

  /// El negocio manda avisos por WhatsApp (y la plataforma lo tiene encendido).
  final bool whatsappDisponible;

  /// Aceptó recibir sus avisos por WhatsApp.
  final bool aceptaWhatsapp;

  /// Tiene un celular válido para WhatsApp (sin él no hay a dónde mandarlos).
  final bool whatsappConCelular;

  /// Al agendar se le ofrecen los avisos por WhatsApp: el negocio los usa, aún no
  /// los aceptó y tiene celular.
  bool get ofrecerWhatsapp =>
      whatsappDisponible && !aceptaWhatsapp && whatsappConCelular;

  /// Su solicitud de baja de datos, si hizo una.
  final SolicitudBaja? baja;

  factory Privacidad.desdeJson(Map<String, dynamic> j) {
    final baja = j['baja'];
    return Privacidad(
      recibePromociones: (j['recibe_promociones'] ?? true) as bool,
      whatsappDisponible: (j['whatsapp_disponible'] ?? false) as bool,
      aceptaWhatsapp: (j['acepta_whatsapp'] ?? false) as bool,
      whatsappConCelular: (j['whatsapp_con_celular'] ?? false) as bool,
      baja: baja is Map<String, dynamic> ? SolicitudBaja.desdeJson(baja) : null,
    );
  }
}

class SolicitudBaja {
  const SolicitudBaja({
    required this.estado,
    this.solicitadaEn,
    this.respuesta,
  });

  /// pendiente | atendida | rechazada.
  final String estado;
  final String? solicitadaEn;
  final String? respuesta;

  String get estadoTexto => switch (estado) {
    'pendiente' => 'Solicitud de baja en revisión',
    'atendida' => 'Tus datos se dieron de baja',
    'rechazada' =>
      respuesta != null && respuesta!.isNotEmpty
          ? 'Solicitud rechazada · $respuesta'
          : 'Solicitud rechazada',
    _ => estado,
  };

  factory SolicitudBaja.desdeJson(Map<String, dynamic> j) => SolicitudBaja(
    estado: (j['estado'] ?? '') as String,
    solicitadaEn: j['solicitada_en'] as String?,
    respuesta: j['respuesta'] as String?,
  );
}

/// El clima del Inicio (GET /mi/clima): el pronóstico para su próxima clase en su
/// sucursal (`tipo` = pronostico) o el de ahora (`ahora`; `aproximado` si salió de
/// su IP). `icono`: despejado, parcial, nublado, niebla, llovizna, lluvia, nieve o
/// tormenta.
class ClimaMiembro {
  const ClimaMiembro({
    required this.tipo,
    required this.lugar,
    required this.aproximado,
    required this.temperatura,
    required this.condicion,
    required this.icono,
    required this.esDeDia,
    this.lluvia,
  });

  factory ClimaMiembro.desdeJson(Map<String, dynamic> json) => ClimaMiembro(
    tipo: (json['tipo'] ?? 'ahora') as String,
    lugar: (json['lugar'] ?? '') as String,
    aproximado: (json['aproximado'] ?? false) as bool,
    temperatura: (json['temperatura'] as num? ?? 0).round(),
    condicion: (json['condicion'] ?? '') as String,
    icono: (json['icono'] ?? 'nublado') as String,
    esDeDia: (json['es_de_dia'] ?? true) as bool,
    lluvia: (json['lluvia'] as num?)?.round(),
  );

  final String tipo;
  final String lugar;
  final bool aproximado;
  final int temperatura;
  final String condicion;
  final String icono;
  final bool esDeDia;
  final int? lluvia;

  /// "Pronóstico para tu clase en Roma Norte", "Ahora cerca de Guadalajara"…
  String dondeTexto(String clase) {
    if (tipo == 'pronostico') {
      return 'Pronóstico para tu $clase en $lugar';
    }
    if (lugar.isEmpty) {
      return '';
    }
    return aproximado ? 'Ahora cerca de $lugar' : 'Ahora en $lugar';
  }
}

/// Un plan que el alumno puede comprar (GET /mi/productos): paquete, membresía, clase
/// suelta o clases extra. Comprar crea la orden; se activa al pagarla.
class ProductoComprable {
  const ProductoComprable({
    required this.id,
    required this.nombre,
    required this.tipo,
    required this.precioMinor,
    required this.ilimitado,
    this.moneda = 'MXN',
    this.creditosIncluidos,
    this.vigenciaTipo,
    this.vigenciaCantidad,
    this.cobertura = const CoberturaSucursales(),
    this.descripcion,
    this.destacado = false,
    this.primeraVez = false,
    this.disponible = true,
    this.venceSiCompraHoy,
    this.condiciones,
    this.inscripcionMinor = 0,
  });

  factory ProductoComprable.desdeJson(
    Map<String, dynamic> j, {
    String moneda = 'MXN',
  }) => ProductoComprable(
    id: j['id'] as String,
    nombre: (j['nombre'] ?? '') as String,
    tipo: (j['tipo'] ?? '') as String,
    precioMinor: (j['precio_minor'] as num? ?? 0).toInt(),
    moneda: Formato.moneda(j['moneda'], moneda),
    ilimitado: (j['ilimitado'] ?? false) as bool,
    creditosIncluidos: (j['creditos_incluidos'] as num?)?.toInt(),
    vigenciaTipo: j['vigencia_tipo'] as String?,
    vigenciaCantidad: (j['vigencia_cantidad'] as num?)?.toInt(),
    cobertura: CoberturaSucursales.desdeJson(j),
    descripcion: j['descripcion'] as String?,
    destacado: (j['destacado'] ?? false) as bool,
    primeraVez: (j['primera_vez'] ?? false) as bool,
    disponible: (j['disponible'] ?? true) as bool,
    venceSiCompraHoy: j['vence_si_compra_hoy'] as String?,
    condiciones: j['condiciones'] as String?,
    inscripcionMinor: (j['inscripcion_minor'] as num? ?? 0).toInt(),
  );

  final String id;
  final String nombre;
  final String tipo;
  final int precioMinor;

  /// En qué sucursales vale (se dice solo si no es en todas).
  final CoberturaSucursales cobertura;

  /// La del plan (la del negocio si no la trae).
  final String moneda;
  final bool ilimitado;
  final int? creditosIncluidos;
  final String? vigenciaTipo;
  final int? vigenciaCantidad;

  /// Qué incluye, en una línea (lo escribe el negocio).
  final String? descripcion;

  /// El que el negocio recomienda: va primero.
  final bool destacado;

  /// Clase de prueba: solo para quien nunca vino.
  final bool primeraVez;

  /// ¿Lo puede comprar? (la clase de prueba, solo la primera vez; lo valida el
  /// servidor al crear la orden y al cobrarla).
  final bool disponible;

  /// Hasta cuándo se puede usar si se paga hoy (AAAA-MM-DD): la vigencia corre
  /// desde el pago.
  final String? venceSiCompraHoy;

  /// Permanencia (B04): las condiciones que acepta antes de comprarlo (null = no hay).
  final String? condiciones;

  /// La inscripción que se suma a la orden si aún no la paga (B04); 0 = no hay.
  final int inscripcionMinor;

  /// "Más $500.00 de inscripción." o null: se dice antes de comprar.
  String? get inscripcionTexto => inscripcionMinor > 0
      ? 'Más ${Formato.dinero(inscripcionMinor, moneda)} de inscripción.'
      : null;

  bool get esExtra => tipo == 'add_on';

  /// "Si la pagas hoy, se puede usar hasta el 8 de octubre." o null.
  String? get venceTexto => venceSiCompraHoy == null
      ? null
      : 'Si la pagas hoy, se puede usar hasta el '
            '${Formato.fechaLarga(venceSiCompraHoy)}.';

  /// "Paquete · Recomendado", "Sesión individual · Para tu primera vez"…
  String get etiqueta => [
    tipoTexto,
    if (destacado) 'Recomendado',
    if (primeraVez) 'Para tu primera vez',
  ].join(' · ');

  /// "Paquete", "Membresía"… (como en la web).
  String get tipoTexto => switch (tipo) {
    'membresia' => 'Membresía',
    'paquete' => 'Paquete',
    'pase_dia' => 'Pase del día',
    'sesion_individual' => 'Sesión individual',
    'add_on' => 'Extra',
    'taller' => 'Taller',
    _ => 'Plan',
  };

  /// "Ilimitado", "8 créditos" o null.
  String? get creditosTexto {
    if (ilimitado) {
      return 'Ilimitado';
    }
    final n = creditosIncluidos;
    if (n == null || n <= 0) {
      return null;
    }
    final c = n / 1000;
    final texto = c == c.roundToDouble() ? c.toInt().toString() : c.toString();
    return c == 1 ? '1 crédito' : '$texto créditos';
  }

  /// Cuánto dura lo que se compra (las clases extra vencen con su paquete).
  String? get vigenciaTexto {
    if (esExtra) {
      return 'Vencen con el paquete';
    }
    final n = vigenciaCantidad ?? 0;
    return switch (vigenciaTipo) {
      'dias' =>
        n == 1
            ? 'Vence 1 día después de la compra'
            : 'Vence $n días después de la compra',
      'meses' =>
        n == 1
            ? 'Vence 1 mes después de la compra'
            : 'Vence $n meses después de la compra',
      'fin_de_mes' =>
        n <= 1
            ? 'Vence al terminar el mes de compra'
            : 'Vence al terminar el mes $n (contando el de compra)',
      _ => null,
    };
  }

  /// Las clases extra solo tienen sentido con un paquete de créditos vigente; la
  /// clase de prueba, solo si todavía le toca. El recomendado va primero.
  static List<ProductoComprable> paraComprar(
    List<ProductoComprable> productos,
    List<DerechoMiembro> derechos,
  ) {
    final tienePaquete = derechos.any((d) => !d.ilimitado);
    final lista = productos
        .where((p) => (!p.esExtra || tienePaquete) && p.disponible)
        .toList();
    // Estable: los demás conservan su orden (del más barato).
    return [
      ...lista.where((p) => p.destacado),
      ...lista.where((p) => !p.destacado),
    ];
  }
}

/// Antes de reservar una clase (GET /mi/sesiones/{id}/antes-de-reservar, M05): si
/// queda reservada, en lista de espera o no se puede (motivo), con qué plan y cuánto
/// consume, y hasta cuándo se cancela sin costo. Lo dice el servidor con la misma regla
/// que al reservar; aquí no se aparta nada.
class AntesDeReservar {
  const AntesDeReservar({
    required this.resultado,
    this.motivo,
    this.mensaje,
    this.unidades = 0,
    this.plan,
    this.planIlimitado = false,
    this.disponibleDespues,
    this.precioMinor,
    this.moneda = 'MXN',
    this.limiteCancelacion,
    this.penalizaTarde = true,
    this.cancelaTarde = true,
    this.queLlevar,
    this.lugaresTotal = 0,
    this.lugaresOcupados = const [],
    this.pasesInvitado = 0,
    this.enLinea = false,
  });

  factory AntesDeReservar.desdeJson(Map<String, dynamic> j) {
    final plan = j['plan'];
    final lugares = j['lugares'];
    final dePago = j['de_pago'];
    final cancelacion = j['cancelacion'];
    return AntesDeReservar(
      resultado: (j['resultado'] ?? 'reserva') as String,
      motivo: j['motivo'] as String?,
      mensaje: j['mensaje'] as String?,
      unidades: (j['unidades'] as num? ?? 0).toInt(),
      plan: plan is Map<String, dynamic> ? plan['nombre'] as String? : null,
      planIlimitado:
          plan is Map<String, dynamic> && (plan['ilimitado'] ?? false) as bool,
      disponibleDespues: plan is Map<String, dynamic>
          ? (plan['disponible_despues'] as num?)?.toInt()
          : null,
      precioMinor: dePago is Map<String, dynamic>
          ? (dePago['precio_minor'] as num?)?.toInt()
          : null,
      moneda: dePago is Map<String, dynamic>
          ? Formato.moneda(dePago['moneda'], 'MXN')
          : 'MXN',
      limiteCancelacion: cancelacion is Map<String, dynamic>
          ? cancelacion['limite'] as String?
          : null,
      penalizaTarde: cancelacion is Map<String, dynamic>
          ? (cancelacion['penaliza_tarde'] ?? true) as bool
          : true,
      cancelaTarde: cancelacion is Map<String, dynamic>
          ? (cancelacion['cancela_tarde'] ?? true) as bool
          : true,
      queLlevar: j['que_llevar'] as String?,
      lugaresTotal: lugares is Map<String, dynamic>
          ? (lugares['total'] as num? ?? 0).toInt()
          : 0,
      lugaresOcupados: lugares is Map<String, dynamic>
          ? ((lugares['ocupados'] ?? const []) as List)
                .whereType<num>()
                .map((n) => n.toInt())
                .toList()
          : const [],
      pasesInvitado: (j['pases_invitado'] as num? ?? 0).toInt(),
      enLinea: j['en_linea'] == true,
    );
  }

  /// Pases de invitado que le quedan para esa clase (ADR 0182).
  final int pasesInvitado;

  /// Clase en línea (ADR 0184).
  final bool enLinea;

  /// Reservarla sería pagar una clase en línea: la app no vende contenido digital.
  bool get pagoDigital => enLinea && precioMinor != null;

  /// Lugares numerados de la clase (0 = no numera) y los ya tomados, sin decir por
  /// quién (B06).
  final int lugaresTotal;
  final List<int> lugaresOcupados;

  /// `reserva`, `lista_espera` o `no_disponible`.
  final String resultado;
  final String? motivo;
  final String? mensaje;
  final int unidades;
  final String? plan;
  final bool planIlimitado;
  final int? disponibleDespues;
  final int? precioMinor;
  final String moneda;
  final String? limiteCancelacion;
  final bool penalizaTarde;
  final bool cancelaTarde;
  final String? queLlevar;

  bool get puedeReservar => resultado != 'no_disponible';
  bool get esListaEspera => resultado == 'lista_espera';

  static String _creditos(int unidades) {
    final c = unidades / 1000;
    final texto = c == c.roundToDouble() ? c.toInt().toString() : c.toString();
    return c == 1 ? '1 crédito' : '$texto créditos';
  }

  /// Qué pasa con su plan (o el pago) si reserva.
  String? get consumoTexto {
    if (!puedeReservar) {
      return null;
    }
    if (precioMinor != null) {
      return 'Se paga al reservar: ${Formato.dinero(precioMinor!, moneda)}. '
          'Tu lugar queda apartado mientras pagas.';
    }
    if (esListaEspera) {
      return 'La clase está llena: te anotas en la lista de espera. No se '
          'descuenta nada hasta que te toque un lugar; te avisamos para que lo '
          'aceptes.';
    }
    final nombre = plan ?? 'tu plan';
    if (planIlimitado || unidades == 0) {
      return 'Usa $nombre: no se descuentan créditos.';
    }
    return 'Usa $nombre: se descuenta ${_creditos(unidades)} y te quedan '
        '${_creditos(disponibleDespues ?? 0)}.';
  }

  /// Hasta cuándo cancela sin costo y qué pasa después (la política que se congela),
  /// con la hora del negocio ([desfaseMin], el de la sesión).
  String? cancelacionTexto(DateTime ahora, {int? desfaseMin}) {
    final limite = limiteCancelacion == null
        ? null
        : DateTime.tryParse(limiteCancelacion!);
    if (resultado != 'reserva' || limite == null) {
      return null;
    }
    final despues = !cancelaTarde
        ? 'Después, solo el negocio puede cancelarla.'
        : penalizaTarde
        ? 'Después, cancelar descuenta la clase.'
        : 'Después también se devuelve el crédito.';
    final hasta = Formato.fechaHora(limiteCancelacion, desfaseMin: desfaseMin);
    return limite.isAfter(ahora)
        ? 'Cancela sin costo hasta el $hasta. $despues'
        : 'Ya pasó el límite para cancelar sin costo. $despues';
  }

  /// Por qué no la puede reservar (o el mensaje del servidor).
  String? get motivoTexto {
    if (puedeReservar) {
      return null;
    }
    return switch (motivo) {
          'sin_plan' => 'Necesitas un plan para reservarla.',
          'clase' => 'Tu plan no incluye esta clase.',
          'pausa' => 'Tu plan está en pausa.',
          'suspendido' => 'Tu plan está suspendido por falta de pago.',
          'vigencia' => 'Tu plan no está vigente ese día.',
          'sucursal' => 'Tu plan no vale en esta sucursal.',
          'saldo' => 'Ya no te quedan clases en tu plan.',
          'ya_reservada' => 'Ya tienes esta clase.',
          'llena' => 'La clase está llena.',
          _ => null,
        } ??
        mensaje;
  }
}

/// Una sede para elegir en el calendario de Reservas.
class SedeAgenda {
  const SedeAgenda({required this.id, required this.nombre});

  final String id;
  final String nombre;
}

/// Las clases de un periodo (GET /mi/agenda con desde/hasta y, si se eligió, la
/// sucursal). `truncado`: había más de las que caben; conviene elegir sede o
/// acortar el periodo.
class AgendaPeriodo {
  const AgendaPeriodo({
    required this.clases,
    this.sucursales = const [],
    this.truncado = false,
  });

  factory AgendaPeriodo.desdeJson(Map<String, dynamic> j) {
    final meta = (j['meta'] ?? const {}) as Map<String, dynamic>;
    return AgendaPeriodo(
      clases: ((j['data'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(ClaseMiembro.desdeJson)
          .toList(),
      sucursales: ((meta['sucursales'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(
            (x) => SedeAgenda(
              id: (x['id'] ?? '') as String,
              nombre: (x['nombre'] ?? '') as String,
            ),
          )
          .toList(),
      truncado: (meta['truncado'] ?? false) as bool,
    );
  }

  final List<ClaseMiembro> clases;
  final List<SedeAgenda> sucursales;
  final bool truncado;
}

/// Una clase o cita de su historial (GET /mi/historial): qué pasó, si cambió de
/// horario y su reseña (o si aún puede calificarla).
class ItemHistorial {
  const ItemHistorial({
    required this.id,
    required this.estado,
    this.tipo,
    this.oferta,
    this.sucursal,
    this.instructor,
    this.iniciaEn,
    this.desfaseMin,
    this.canceladaPor,
    this.reprogramada = false,
    this.retardo = false,
    this.calificacion,
    this.comentario,
    this.calificable = false,
  });

  final String id;
  final String estado;
  final String? tipo;
  final String? oferta;
  final String? sucursal;
  final String? instructor;
  final String? iniciaEn;

  /// El desfase con UTC de su sede (`utc_offset_min`); null si el API no lo
  /// manda (se usa el del negocio).
  final int? desfaseMin;
  final String? canceladaPor;
  final bool reprogramada;

  /// Llegó tarde (cuenta como asistencia, ADR 0101).
  final bool retardo;
  final int? calificacion;
  final String? comentario;
  final bool calificable;

  String get estadoTexto => switch (estado) {
    'asistio' => retardo ? 'Asististe (llegaste tarde)' : 'Asististe',
    'no_asistio' => 'No asististe',
    'cancelada' =>
      canceladaPor == 'cliente' ? 'Cancelaste' : 'Cancelada por el negocio',
    'expirada' => 'Lugar sin aceptar',
    'sin_lugar' => 'Te quedaste en lista de espera',
    'sin_pagar' => 'Sin pagar',
    _ => 'Reservada',
  };

  factory ItemHistorial.desdeJson(Map<String, dynamic> j) {
    final resena = j['resena'];
    return ItemHistorial(
      id: (j['id'] ?? '') as String,
      estado: (j['estado'] ?? '') as String,
      tipo: j['tipo'] as String?,
      oferta: j['oferta'] as String?,
      sucursal: j['sucursal'] as String?,
      instructor: j['instructor'] as String?,
      iniciaEn: j['inicia_en'] as String?,
      desfaseMin: (j['utc_offset_min'] as num?)?.toInt(),
      canceladaPor: j['cancelada_por'] as String?,
      retardo: (j['retardo'] ?? false) as bool,
      reprogramada: (j['reprogramada'] ?? false) as bool,
      calificacion: resena is Map<String, dynamic>
          ? (resena['calificacion'] as num?)?.toInt()
          : null,
      comentario: resena is Map<String, dynamic>
          ? resena['comentario'] as String?
          : null,
      calificable: (j['calificable'] ?? false) as bool,
    );
  }
}

/// Una página del historial.
class PaginaHistorial {
  const PaginaHistorial({
    required this.items,
    required this.pagina,
    required this.ultimaPagina,
  });

  final List<ItemHistorial> items;
  final int pagina;
  final int ultimaPagina;

  factory PaginaHistorial.desdeJson(Map<String, dynamic> j) {
    final meta = (j['meta'] ?? const {}) as Map<String, dynamic>;
    return PaginaHistorial(
      items: ((j['data'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(ItemHistorial.desdeJson)
          .toList(),
      pagina: (meta['page'] as num? ?? 1).toInt(),
      ultimaPagina: (meta['ultima_pagina'] as num? ?? 1).toInt(),
    );
  }
}

/// Un producto que el negocio ofrece al reservar (ADR 0194): se recoge y se paga en
/// el mostrador el día de la clase.
class ArticuloAlReservar {
  const ArticuloAlReservar({
    required this.id,
    required this.nombre,
    required this.precioMinor,
    required this.moneda,
    required this.disponibles,
  });

  final String id;
  final String nombre;
  final int precioMinor;
  final String moneda;
  final int disponibles;

  factory ArticuloAlReservar.desdeJson(Map<String, dynamic> j) =>
      ArticuloAlReservar(
        id: j['id'] as String,
        nombre: j['nombre'] as String? ?? '—',
        precioMinor: (j['precio_minor'] as num? ?? 0).toInt(),
        moneda: j['moneda'] as String? ?? 'MXN',
        disponibles: (j['disponibles'] as num? ?? 0).toInt(),
      );
}

/// Lo que ya apartó para esa clase.
class ApartadoDeClase {
  const ApartadoDeClase({
    required this.id,
    required this.nombre,
    required this.cantidad,
    required this.totalMinor,
    required this.moneda,
  });

  final String id;
  final String nombre;
  final int cantidad;
  final int totalMinor;
  final String moneda;

  factory ApartadoDeClase.desdeJson(Map<String, dynamic> j) => ApartadoDeClase(
    id: j['id'] as String,
    nombre:
        ((j['articulo'] ?? const <String, dynamic>{})
                as Map<String, dynamic>)['nombre']
            as String? ??
        '—',
    cantidad: (j['cantidad'] as num? ?? 1).toInt(),
    totalMinor: (j['total_minor'] as num? ?? 0).toInt(),
    moneda: j['moneda'] as String? ?? 'MXN',
  );
}
