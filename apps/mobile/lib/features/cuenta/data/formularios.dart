/// Las fichas de datos que el negocio pide llenar (formularios dinámicos), con lo
/// que ya respondió: GET /mi/formularios.
class FormulariosDeCuenta {
  const FormulariosDeCuenta({
    required this.personaId,
    required this.formularios,
  });

  /// De quién son (la cuenta o la persona a su cargo): va al guardar.
  final String? personaId;
  final List<FormularioPersona> formularios;

  static const vacio = FormulariosDeCuenta(personaId: null, formularios: []);

  factory FormulariosDeCuenta.desdeJson(Map<String, dynamic> j) =>
      FormulariosDeCuenta(
        personaId: j['persona_id'] as String?,
        formularios: ((j['formularios'] ?? []) as List)
            .whereType<Map<String, dynamic>>()
            .map(FormularioPersona.desdeJson)
            .toList(),
      );
}

class FormularioPersona {
  const FormularioPersona({
    required this.id,
    required this.nombre,
    required this.campos,
    this.descripcion,
    this.respondidoEn,
  });

  final String id;
  final String nombre;
  final String? descripcion;

  /// Cuándo lo respondió por última vez (null: sin responder).
  final DateTime? respondidoEn;
  final List<CampoFormulario> campos;

  factory FormularioPersona.desdeJson(Map<String, dynamic> j) =>
      FormularioPersona(
        id: j['id'] as String,
        nombre: (j['nombre'] ?? '') as String,
        descripcion: j['descripcion'] as String?,
        respondidoEn: j['respondido_en'] is String
            ? DateTime.tryParse(j['respondido_en'] as String)?.toLocal()
            : null,
        campos: ((j['campos'] ?? []) as List)
            .whereType<Map<String, dynamic>>()
            .map(CampoFormulario.desdeJson)
            .toList(),
      );
}

/// Un campo: texto, textarea, numero, fecha (AAAA-MM-DD), booleano o seleccion.
class CampoFormulario {
  const CampoFormulario({
    required this.id,
    required this.etiqueta,
    required this.tipo,
    this.obligatorio = false,
    this.opciones = const [],
    this.valor,
  });

  final String id;
  final String etiqueta;
  final String tipo;
  final bool obligatorio;
  final List<String> opciones;

  /// Lo que respondió (String, num o bool; null si nada).
  final Object? valor;

  factory CampoFormulario.desdeJson(Map<String, dynamic> j) => CampoFormulario(
    id: j['id'] as String,
    etiqueta: (j['etiqueta'] ?? '') as String,
    tipo: (j['tipo'] ?? 'texto') as String,
    obligatorio: (j['obligatorio'] ?? false) as bool,
    opciones: ((j['opciones'] ?? []) as List).map((o) => '$o').toList(),
    valor: j['valor'],
  );
}
