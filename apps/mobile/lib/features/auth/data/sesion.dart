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
