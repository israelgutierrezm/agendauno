/// El mes en curso para el Inicio de quien ve los números del negocio (ADR 0081):
/// del resumen (`GET /reportes/negocio`) y de la agenda del equipo
/// (`GET /reportes/equipo`).
class ResumenMes {
  const ResumenMes({
    required this.ingresosMinor,
    required this.ocupacionPct,
    required this.inasistenciaPct,
    required this.clientesActivos,
    required this.equipo,
  });

  /// En negocios de citas la ocupación es la de la agenda del equipo (el cupo de
  /// cada cita es 1); en los de clases, la del cupo.
  factory ResumenMes.desdeJson(
    Map<String, dynamic> negocio,
    Map<String, dynamic> equipo, {
    required bool esCitas,
  }) {
    final profesionales = equipo['profesionales'];
    return ResumenMes(
      ingresosMinor: (negocio['ingresos_minor'] as num?)?.toInt() ?? 0,
      ocupacionPct:
          ((esCitas
                      ? negocio['ocupacion_agenda_pct']
                      : negocio['ocupacion_pct'])
                  as num?)
              ?.toInt(),
      inasistenciaPct: (negocio['no_show_pct'] as num?)?.toInt(),
      clientesActivos: (negocio['alumnos_activos'] as num?)?.toInt() ?? 0,
      equipo: profesionales is List
          ? profesionales
                .whereType<Map<String, dynamic>>()
                .map(OcupacionProfesional.desdeJson)
                .toList()
          : const [],
    );
  }

  final int ingresosMinor;
  final int? ocupacionPct;
  final int? inasistenciaPct;
  final int clientesActivos;
  final List<OcupacionProfesional> equipo;
}

/// La agenda de una persona del equipo en el mes.
class OcupacionProfesional {
  const OcupacionProfesional({
    required this.nombre,
    required this.ocupacionPct,
    required this.agendadoMin,
    required this.disponibleMin,
  });

  factory OcupacionProfesional.desdeJson(Map<String, dynamic> j) =>
      OcupacionProfesional(
        nombre: j['nombre'] as String?,
        ocupacionPct: (j['ocupacion_pct'] as num?)?.toInt(),
        agendadoMin: (j['agendado_en_horario_min'] as num?)?.toInt() ?? 0,
        disponibleMin: (j['disponible_min'] as num?)?.toInt() ?? 0,
      );

  /// Null en la fila de lo que no tuvo profesional.
  final String? nombre;

  /// Null si no tiene horario de atención.
  final int? ocupacionPct;
  final int agendadoMin;
  final int disponibleMin;
}
