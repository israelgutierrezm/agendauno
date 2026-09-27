import 'package:flutter/material.dart';

import '../../../core/theme/tema_agendauno.dart';

/// Modelos del autoservicio del miembro (Mi cuenta).
class DerechoMiembro {
  const DerechoMiembro({
    required this.ilimitado,
    this.id,
    this.saldo,
    this.disponible,
    this.producto,
    this.pausaHasta,
  });

  final bool ilimitado;
  final String? id;
  final int? saldo;
  final int? disponible;
  final String? producto;

  /// Último día en pausa (AAAA-MM-DD) si la membresía está congelada.
  final String? pausaHasta;

  /// Créditos disponibles (1 crédito = 1000 unidades).
  int get creditosDisponibles => ((disponible ?? 0) / 1000).round();

  factory DerechoMiembro.desdeJson(Map<String, dynamic> j) => DerechoMiembro(
    ilimitado: (j['ilimitado'] ?? false) as bool,
    id: j['id'] as String?,
    saldo: j['saldo'] as int?,
    disponible: j['disponible'] as int?,
    producto: j['producto'] as String?,
    pausaHasta: j['pausa_hasta'] as String?,
  );
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

  /// 'cita' o 'clase'.
  final String tipo;
  final int restantes;

  /// Cita: inicio (ISO UTC) de cada horario libre.
  final List<String> horarios;

  /// Clase: (id, inicio ISO) de cada fecha con lugar.
  final List<({String id, String iniciaEn})> sesiones;

  factory OpcionesReprogramar.desdeJson(Map<String, dynamic> j) =>
      OpcionesReprogramar(
        puede: (j['puede'] ?? false) as bool,
        motivo: j['motivo'] as String?,
        tipo: (j['tipo'] ?? 'cita') as String,
        restantes: (j['restantes'] ?? 0) as int,
        horarios: ((j['slots'] ?? []) as List)
            .map((s) => ((s as Map<String, dynamic>)['inicia'] ?? '') as String)
            .where((s) => s.isNotEmpty)
            .toList(),
        sesiones: ((j['sesiones'] ?? []) as List).map((s) {
          final m = s as Map<String, dynamic>;
          return (
            id: (m['id'] ?? '') as String,
            iniciaEn: (m['inicia_en'] ?? '') as String,
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
  });

  final String id;
  final String concepto;
  final int unidades;
  final int saldoPosterior;
  final String? fecha;
  final String? clase;
  final String? claseIniciaEn;

  /// "+8", "-1", "-0.5" (1 crédito = 1000 unidades).
  static String creditos(int unidades, {bool conSigno = true}) {
    final n = unidades / 1000;
    final texto = n == n.roundToDouble()
        ? n.round().toString()
        : n.toStringAsFixed(1);
    return conSigno && n > 0 ? '+$texto' : texto;
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
    );
  }
}

class ReservaMiembro {
  const ReservaMiembro({
    required this.id,
    required this.estado,
    this.sesionId,
    this.oferta,
    this.sucursal,
    this.iniciaEn,
    this.terminaEn,
    this.instructor,
    this.zonaHoraria,
    this.ofertaExpiraEn,
    this.ordenId,
  });

  final String id;
  final String estado;
  final String? sesionId;
  final String? oferta;
  final String? sucursal;
  final String? iniciaEn;
  final String? terminaEn;

  /// Con quién (profesional o instructor), si el negocio lo asignó.
  final String? instructor;
  final String? zonaHoraria;
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

  factory ReservaMiembro.desdeJson(Map<String, dynamic> j) => ReservaMiembro(
    id: (j['id'] ?? '') as String,
    estado: (j['estado'] ?? '') as String,
    sesionId: j['sesion_id'] as String?,
    oferta: j['oferta'] as String?,
    sucursal: j['sucursal'] as String?,
    iniciaEn: j['inicia_en'] as String?,
    terminaEn: j['termina_en'] as String?,
    instructor: j['instructor'] as String?,
    zonaHoraria: j['zona_horaria'] as String?,
    ofertaExpiraEn: j['oferta_expira_en'] as String?,
    ordenId: j['orden_id'] as String?,
  );
}

class ClaseMiembro {
  const ClaseMiembro({
    required this.id,
    this.oferta,
    this.sucursal,
    this.iniciaEn,
    this.terminaEn,
    this.instructor,
    this.zonaHoraria,
    this.capacidad,
    this.ocupados = 0,
  });

  final String id;
  final String? oferta;
  final String? sucursal;
  final String? iniciaEn;
  final String? terminaEn;
  final String? instructor;
  final String? zonaHoraria;
  final int? capacidad;
  final int ocupados;

  /// Sin lugares: se ofrece anotarse en la lista de espera.
  bool get llena => capacidad != null && ocupados >= capacidad!;

  factory ClaseMiembro.desdeJson(Map<String, dynamic> j) => ClaseMiembro(
    id: (j['id'] ?? '') as String,
    oferta: j['oferta'] as String?,
    sucursal: j['sucursal'] as String?,
    iniciaEn: j['inicia_en'] as String?,
    terminaEn: j['termina_en'] as String?,
    instructor: j['instructor'] as String?,
    zonaHoraria: j['zona_horaria'] as String?,
    capacidad: j['capacidad'] as int?,
    ocupados: (j['ocupados'] ?? 0) as int,
  );
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
  });

  final String id;
  final String nombre;
  final int? duracionMinutos;
  final String? zonaHoraria;

  factory OpcionCita.desdeJson(Map<String, dynamic> j) => OpcionCita(
    id: (j['id'] ?? '') as String,
    nombre: (j['nombre'] ?? '') as String,
    duracionMinutos: j['duracion_minutos'] as int?,
    zonaHoraria: j['zona_horaria'] as String?,
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
}

/// Lo que el alumno tiene por pagar: p. ej. la renovación de su membresía, que se
/// abre unos días antes para pagarla por adelantado. Una cita apartada se paga
/// desde su reserva.
class OrdenPorPagar {
  const OrdenPorPagar({
    required this.id,
    required this.concepto,
    required this.totalMinor,
  });

  final String id;
  final String concepto;
  final int totalMinor;

  /// Las órdenes pendientes con productos (las de una cita no traen productos).
  static List<OrdenPorPagar> pendientes(List<dynamic> ordenes) => ordenes
      .whereType<Map<String, dynamic>>()
      .where(
        (o) =>
            o['estado'] == 'pendiente' &&
            ((o['lineas'] ?? []) as List).isNotEmpty,
      )
      .map(OrdenPorPagar.desdeJson)
      .toList();

  factory OrdenPorPagar.desdeJson(Map<String, dynamic> j) {
    final lineas = ((j['lineas'] ?? []) as List)
        .whereType<Map<String, dynamic>>()
        .map((l) {
          final nombre = (l['producto'] as String?) ?? 'Producto';
          final cantidad = (l['cantidad'] as int?) ?? 1;
          return cantidad > 1 ? '$nombre × $cantidad' : nombre;
        })
        .toList();
    return OrdenPorPagar(
      id: j['id'] as String,
      concepto: lineas.join(', '),
      totalMinor: (j['total_minor'] as int?) ?? 0,
    );
  }
}

/// Estado agregado de la pantalla Mi cuenta.
class MiCuenta {
  const MiCuenta({
    required this.derechos,
    required this.reservas,
    required this.clases,
    this.consentimientos = const [],
    this.pagoEnLinea = false,
    this.pagoAutomatico = false,
    this.resenasPendientes = const [],
    this.porPagar = const [],
  });

  /// Lo que tiene pendiente de pago (p. ej. su renovación).
  final List<OrdenPorPagar> porPagar;

  /// Clases o citas a las que asistió y aún no califica.
  final List<ResenaPendiente> resenasPendientes;

  /// ¿El negocio cobra en línea? Entonces puede pagar aquí lo pendiente.
  final bool pagoEnLinea;

  /// ¿La pasarela admite pago automático (domiciliar sus membresías)?
  final bool pagoAutomatico;
  final List<DerechoMiembro> derechos;
  final List<ReservaMiembro> reservas;
  final List<ClaseMiembro> clases;
  final List<ConsentimientoPendiente> consentimientos;

  /// Las clases que ya reservó (o en las que espera), para no ofrecerlas de nuevo.
  Set<String> get sesionesReservadas =>
      reservas.map((r) => r.sesionId).whereType<String>().toSet();
}

/// Documento que pide el negocio y cómo va el del alumno.
class RequisitoDocumento {
  const RequisitoDocumento({
    required this.tipoId,
    required this.nombre,
    this.obligatorio = false,
    this.estado,
    this.motivo,
  });

  final String tipoId;
  final String nombre;
  final bool obligatorio;

  /// null (falta) | pendiente | aprobado | rechazado.
  final String? estado;
  final String? motivo;

  String get estadoTexto => switch (estado) {
    'pendiente' => 'En revisión',
    'aprobado' => 'Aprobado',
    'rechazado' => motivo != null ? 'Rechazado · $motivo' : 'Rechazado',
    _ => 'Falta subirlo',
  };

  Color get color => switch (estado) {
    'pendiente' => TemaAgendaUno.aviso,
    'aprobado' => TemaAgendaUno.exito,
    'rechazado' => TemaAgendaUno.error,
    _ => TemaAgendaUno.textoSuave,
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
    );
  }
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
    this.proximaCobroEn,
    this.error,
    this.tarjeta,
  });

  final String id;
  final bool automatico;

  /// Suscripción creada en la pasarela que falta autorizar.
  final bool pendiente;

  /// Con qué tarjeta se cobra (en suscripciones, una por membresía).
  final TarjetaDomiciliada? tarjeta;
  final String? producto;
  final int? montoMinor;
  final String? proximaCobroEn;
  final String? error;

  factory MembresiaRenovable.desdeJson(Map<String, dynamic> j) =>
      MembresiaRenovable(
        id: (j['id'] ?? '') as String,
        automatico: (j['automatico'] ?? false) as bool,
        pendiente: (j['pendiente'] ?? false) as bool,
        tarjeta: j['tarjeta'] is Map<String, dynamic>
            ? TarjetaDomiciliada.desdeJson(j['tarjeta'] as Map<String, dynamic>)
            : null,
        producto: j['producto'] as String?,
        montoMinor: j['monto_minor'] as int?,
        proximaCobroEn: j['proxima_cobro_en'] as String?,
        error: j['error'] as String?,
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
  });

  final String reservaId;
  final String? actividad;
  final String? con;
  final String? fecha;

  factory ResenaPendiente.desdeJson(Map<String, dynamic> j) => ResenaPendiente(
    reservaId: (j['reserva_id'] ?? '') as String,
    actividad: j['actividad'] as String?,
    con: j['con'] as String?,
    fecha: j['fecha'] as String?,
  );
}

/// Privacidad del alumno frente al negocio (derechos ARCO).
class Privacidad {
  const Privacidad({required this.recibePromociones, this.baja});

  final bool recibePromociones;

  /// Su solicitud de baja de datos, si hizo una.
  final SolicitudBaja? baja;

  factory Privacidad.desdeJson(Map<String, dynamic> j) {
    final baja = j['baja'];
    return Privacidad(
      recibePromociones: (j['recibe_promociones'] ?? true) as bool,
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
