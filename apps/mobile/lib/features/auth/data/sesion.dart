/// Cómo atiende el negocio a su gente (derivado de su perfil en el servidor):
/// clases con cupo o citas 1 a 1 con un profesional. La app adapta la agenda, las
/// etiquetas y las opciones a esta modalidad (sin ramas por industria).
enum Modalidad {
  clases,
  citas;

  static Modalidad desde(Object? valor) => valor == 'citas' ? Modalidad.citas : Modalidad.clases;
}

/// Terminología del perfil de negocio (p. ej. Cita / Cliente / Barbero).
class Terminologia {
  const Terminologia({this.sesion = 'Clase', this.miembro = 'Miembro', this.instructor = 'Instructor'});

  final String sesion;
  final String miembro;
  final String instructor;

  Map<String, dynamic> aJson() => {'sesion': sesion, 'miembro': miembro, 'instructor': instructor};

  factory Terminologia.desdeJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const Terminologia();
    }
    return Terminologia(
      sesion: (json['sesion'] ?? 'Clase') as String,
      miembro: (json['miembro'] ?? 'Miembro') as String,
      instructor: (json['instructor'] ?? 'Instructor') as String,
    );
  }
}

/// Sesion tenant-local activa: el estudio (slug), el usuario autenticado y cómo
/// opera el negocio (modalidad + terminología de su perfil).
class Sesion {
  const Sesion({
    required this.slug,
    required this.bearer,
    required this.nombre,
    required this.rol,
    this.modalidad = Modalidad.clases,
    this.terminologia = const Terminologia(),
  });

  final String slug;
  final String bearer;
  final String nombre;
  final String rol;
  final Modalidad modalidad;
  final Terminologia terminologia;

  bool get esCitas => modalidad == Modalidad.citas;

  /// Forma guardada en el almacén cifrado del dispositivo.
  Map<String, dynamic> aJson() => {
    'slug': slug,
    'bearer': bearer,
    'nombre': nombre,
    'rol': rol,
    'modalidad': modalidad.name,
    'terminologia': terminologia.aJson(),
  };

  /// Restaura una sesión guardada (null si le falta lo esencial).
  static Sesion? desdeAlmacen(Map<String, dynamic> datos) {
    final slug = datos['slug'];
    final bearer = datos['bearer'];
    if (slug is! String || bearer is! String || slug.isEmpty || bearer.isEmpty) {
      return null;
    }
    return Sesion(
      slug: slug,
      bearer: bearer,
      nombre: (datos['nombre'] ?? '') as String,
      rol: (datos['rol'] ?? '') as String,
      modalidad: Modalidad.desde(datos['modalidad']),
      terminologia: Terminologia.desdeJson(datos['terminologia'] as Map<String, dynamic>?),
    );
  }

  factory Sesion.desdeJson(String slug, String bearer, Map<String, dynamic> usuario, [Map<String, dynamic>? estudio]) {
    final config = estudio?['perfil_config'] as Map<String, dynamic>?;
    return Sesion(
      slug: slug,
      bearer: bearer,
      nombre: (usuario['nombre'] ?? '') as String,
      rol: (usuario['rol'] ?? '') as String,
      modalidad: Modalidad.desde(config?['modalidad']),
      terminologia: Terminologia.desdeJson(config?['terminologia'] as Map<String, dynamic>?),
    );
  }
}
