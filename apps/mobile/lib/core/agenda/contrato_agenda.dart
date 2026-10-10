/// Contrato de la agenda que comparten el equipo y la cuenta del cliente (ADR
/// 0104): cada sesión dice su `tipo` y trae el bloque de ese tipo (`clase` o
/// `cita`) con el otro en null. La ocupación que se muestra y en qué va una cita
/// los calcula el servidor; la app solo los lee.
library;

/// Qué es una sesión: una clase con cupo o una cita con un cliente. Lo decide la
/// modalidad del negocio (solo clases o solo citas).
enum TipoSesion {
  clase,
  cita;

  /// El tipo que manda el servidor. Uno que la app no conoce (o que falta) es un
  /// error: no se supone clase ni cita.
  static TipoSesion desde(Object? valor) => switch (valor) {
    'clase' => TipoSesion.clase,
    'cita' => TipoSesion.cita,
    _ => throw TipoSesionDesconocido(valor),
  };
}

/// El servidor mandó un tipo de sesión que esta versión de la app no conoce (o uno
/// que no va donde llegó: una cita en la lista de clases).
class TipoSesionDesconocido implements Exception {
  const TipoSesionDesconocido(this.valor, {this.esperado});

  final Object? valor;

  /// El tipo que se esperaba en esa respuesta, si solo admite uno.
  final TipoSesion? esperado;

  @override
  String toString() => esperado != null
      ? 'Se esperaba una ${esperado!.name} y llegó «$valor».'
      : valor == null
      ? 'La sesión no dice si es clase o cita.'
      : 'Tipo de sesión desconocido: «$valor». Actualiza la app.';
}

/// La ocupación de una clase que se muestra, de 0 a 100 (con sobrecupo se queda en
/// 100). Solo viene en clases con cupo.
class Ocupacion {
  const Ocupacion({
    required this.ocupados,
    required this.capacidad,
    required this.porcentaje,
  });

  final int ocupados;
  final int capacidad;
  final int porcentaje;

  /// De 0 a 1, para un indicador de progreso.
  double get fraccion => porcentaje.clamp(0, 100) / 100;

  static Ocupacion? desdeJson(Object? j) => j is Map<String, dynamic>
      ? Ocupacion(
          ocupados: (j['ocupados'] as num? ?? 0).toInt(),
          capacidad: (j['capacidad'] as num? ?? 0).toInt(),
          porcentaje: (j['porcentaje'] as num? ?? 0).toInt(),
        )
      : null;
}

/// Lo propio de una clase: cupo, lugares libres, lista de espera y si se paga por
/// clase.
class BloqueClase {
  const BloqueClase({
    this.capacidad,
    this.ocupados = 0,
    this.libres,
    this.enEspera = 0,
    this.lugares = 0,
    this.dePago = false,
    this.precioMinor,
  });

  /// Null: sin cupo.
  final int? capacidad;
  final int ocupados;

  /// Null: sin cupo.
  final int? libres;
  final int enEspera;

  /// Lugares numerados (0 = sin mapa de lugares).
  final int lugares;
  final bool dePago;

  /// Precio de la clase suelta, solo si es de pago.
  final int? precioMinor;

  /// Sin lugares: se ofrece la lista de espera.
  bool get llena => libres == 0;

  /// El bloque `clase` del servidor. Un API anterior no lo mandaba: entonces se
  /// arma con los conteos del primer nivel (capacidad, ocupados, en_espera), que se
  /// conservan en la respuesta.
  static BloqueClase desdeJson(
    Object? bloque, {
    Map<String, dynamic> respaldo = const {},
  }) {
    if (bloque is Map<String, dynamic>) {
      return BloqueClase(
        capacidad: (bloque['capacidad'] as num?)?.toInt(),
        ocupados: (bloque['ocupados'] as num? ?? 0).toInt(),
        libres: (bloque['libres'] as num?)?.toInt(),
        enEspera: (bloque['en_espera'] as num? ?? 0).toInt(),
        lugares: (bloque['lugares'] as num? ?? 0).toInt(),
        dePago: bloque['de_pago'] == true,
        precioMinor: (bloque['precio_minor'] as num?)?.toInt(),
      );
    }
    final capacidad = (respaldo['capacidad'] as num?)?.toInt();
    final ocupados = (respaldo['ocupados'] as num? ?? 0).toInt();
    return BloqueClase(
      capacidad: capacidad,
      ocupados: ocupados,
      libres: capacidad == null
          ? null
          : (capacidad - ocupados).clamp(0, capacidad),
      enEspera: (respaldo['en_espera'] as num? ?? 0).toInt(),
    );
  }
}

/// En qué va la atención de una cita, como la calcula el servidor a la hora de la
/// respuesta.
enum EstadoCita {
  confirmada('confirmada', 'Confirmada'),
  sinRegistrar('sin_registrar', 'Sin registrar'),
  llego('llego', 'Llegó'),
  enServicio('en_servicio', 'En servicio'),
  completada('completada', 'Completada'),
  noAsistio('no_asistio', 'No asistió'),
  cancelada('cancelada', 'Cancelada');

  const EstadoCita(this.clave, this.etiqueta);

  final String clave;
  final String etiqueta;

  /// Ya no hay nada que hacer con ella (no se marca, no se cobra, no se cancela).
  bool get cerrada =>
      this == EstadoCita.cancelada ||
      this == EstadoCita.completada ||
      this == EstadoCita.noAsistio;

  /// Null si no vino (un API anterior) o si esta versión no lo conoce: no se
  /// muestra en lugar de suponerlo.
  static EstadoCita? desde(Object? valor) {
    for (final e in values) {
      if (e.clave == valor) {
        return e;
      }
    }
    return null;
  }
}

/// El pago de una cita, aparte de su atención: apartada en línea sin pagar, por
/// cobrar o pagada. Null: no lleva cobro (la toma con su plan) o su orden se
/// canceló.
enum EstadoPagoCita {
  porPagar('por_pagar', 'Pago pendiente'),
  porCobrar('por_cobrar', 'Por cobrar'),
  pagada('pagada', 'Pagada');

  const EstadoPagoCita(this.clave, this.etiqueta);

  final String clave;
  final String etiqueta;

  /// Falta que alguien la pague.
  bool get pendiente =>
      this == EstadoPagoCita.porPagar || this == EstadoPagoCita.porCobrar;

  static EstadoPagoCita? desde(Object? valor) {
    for (final e in values) {
      if (e.clave == valor) {
        return e;
      }
    }
    return null;
  }
}
