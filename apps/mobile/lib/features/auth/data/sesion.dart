/// Cómo atiende el negocio a su gente (derivado de su perfil en el servidor):
/// clases con cupo o citas 1 a 1 con un profesional. La app adapta la agenda, las
/// etiquetas y las opciones a esta modalidad (sin ramas por industria).
enum Modalidad {
  clases,
  citas;

  static Modalidad desde(Object? valor) =>
      valor == 'citas' ? Modalidad.citas : Modalidad.clases;
}

/// Terminología del negocio (p. ej. Cita / Cliente / Barbero), con plurales. La
/// fija su giro y el administrador puede cambiarla (ADR 0049).
class Terminologia {
  const Terminologia({
    this.sesion = 'Clase',
    this.miembro = 'Miembro',
    this.instructor = 'Instructor',
    this.sesionesPlural,
    this.miembrosPlural,
    this.instructoresPlural,
  });

  final String sesion;
  final String miembro;
  final String instructor;
  // Plurales tal como los manda el servidor (Lecciones, Coaches…).
  final String? sesionesPlural;
  final String? miembrosPlural;
  final String? instructoresPlural;

  String get sesiones => sesionesPlural ?? _plural(sesion);
  String get miembros => miembrosPlural ?? _plural(miembro);
  String get instructores => instructoresPlural ?? _plural(instructor);

  static String _plural(String p) {
    if (p.isEmpty) {
      return p;
    }
    return 'aeiouáéó'.contains(p[p.length - 1].toLowerCase())
        ? '${p}s'
        : '${p}es';
  }

  Map<String, dynamic> aJson() => {
    'sesion': sesion,
    'sesiones': sesiones,
    'miembro': miembro,
    'miembros': miembros,
    'instructor': instructor,
    'instructores': instructores,
  };

  factory Terminologia.desdeJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const Terminologia();
    }
    return Terminologia(
      sesion: (json['sesion'] ?? 'Clase') as String,
      miembro: (json['miembro'] ?? 'Miembro') as String,
      instructor: (json['instructor'] ?? 'Instructor') as String,
      sesionesPlural: json['sesiones'] as String?,
      miembrosPlural: json['miembros'] as String?,
      instructoresPlural: json['instructores'] as String?,
    );
  }
}

/// Un rol con el que la persona puede entrar al negocio y la parte de la app que le
/// toca: `equipo` (el negocio), `instructor` (su portal) o `miembro` (su cuenta).
class RolDisponible {
  const RolDisponible({required this.clave, required this.faceta, this.nombre});

  final String clave;
  final String faceta;

  /// Nombre propio del rol (roles creados por el negocio); los de sistema se
  /// nombran en la app.
  final String? nombre;

  static const _facetas = {
    'propietario': 'equipo',
    'admin': 'equipo',
    'recepcionista': 'equipo',
    'instructor': 'instructor',
    'miembro': 'miembro',
  };

  /// Faceta de un rol de sistema (los propios la traen del servidor).
  static String facetaDe(String clave) => _facetas[clave] ?? 'equipo';

  Map<String, dynamic> aJson() => {
    'clave': clave,
    'faceta': faceta,
    'nombre': nombre,
  };

  static List<RolDisponible> lista(Object? valor) => valor is List
      ? valor
            .whereType<Map<String, dynamic>>()
            .map(
              (r) => RolDisponible(
                clave: (r['clave'] ?? '') as String,
                faceta:
                    (r['faceta'] ?? facetaDe((r['clave'] ?? '') as String))
                        as String,
                nombre: r['nombre'] as String?,
              ),
            )
            .where((r) => r.clave.isNotEmpty)
            .toList(growable: false)
      : const [];
}

/// Sesion tenant-local activa: el estudio (slug), el usuario autenticado (con sus
/// roles y permisos, para no ofrecer acciones que el servidor rechazaría) y cómo
/// opera el negocio (modalidad + terminología de su perfil).
///
/// `rol` es el rol ACTIVO: con el que entró. Su pantalla de inicio, lo que ve y lo
/// que el servidor le concede son solo de ese rol; quien tiene varios elige al
/// entrar (`eligiendoRol`) y cambia desde su perfil.
class Sesion {
  const Sesion({
    required this.slug,
    required this.bearer,
    required this.nombre,
    required this.rol,
    this.roles = const [],
    this.rolesDisponibles = const [],
    this.eligiendoRol = false,
    this.permisos = const [],
    this.nombrePila,
    this.primerApellido,
    this.segundoApellido,
    this.email,
    this.emailPendiente,
    this.fotoUrl,
    this.tieneContrasena = true,
    this.googleConectado = false,
    this.modalidad = Modalidad.clases,
    this.terminologia = const Terminologia(),
    this.estudioNombre,
    this.perfil,
  });

  final String slug;
  final String bearer;
  final String nombre;
  final String rol;
  final List<String> roles;

  /// Roles con los que puede entrar (con su faceta).
  final List<RolDisponible> rolesDisponibles;

  /// Recién entró y tiene varios roles: falta elegir con cuál (no se guarda).
  final bool eligiendoRol;
  final List<String> permisos;
  final String? nombrePila;
  final String? primerApellido;
  final String? segundoApellido;
  final String? email;

  /// Correo nuevo que espera confirmación por enlace.
  final String? emailPendiente;
  final String? fotoUrl;
  final bool tieneContrasena;

  /// Conectó Google para entrar con él (se conecta desde su perfil, ADR 0093).
  final bool googleConectado;
  final Modalidad modalidad;
  final Terminologia terminologia;

  /// Nombre del negocio y su giro (perfil: pole, barberia, spa…).
  final String? estudioNombre;
  final String? perfil;

  bool get esCitas => modalidad == Modalidad.citas;

  /// La parte de la app del rol activo: equipo, instructor o miembro.
  String get facetaActiva {
    for (final r in rolesDisponibles) {
      if (r.clave == rol) {
        return r.faceta;
      }
    }
    return RolDisponible.facetaDe(rol);
  }

  /// Entró como alumno o cliente: ve su cuenta.
  bool get esMiembro => facetaActiva == 'miembro';

  /// Entró como quien imparte: su portal (sus clases o citas); el servidor ya le
  /// acota la agenda.
  bool get esInstructorAcotado => facetaActiva == 'instructor';

  /// ¿Puede entrar con más de un rol? Elige al entrar y cambia desde su perfil.
  bool get tieneVariosRoles => rolesDisponibles.length > 1;

  /// ¿Tiene el permiso? (el propietario los tiene todos).
  bool puede(String permiso) =>
      permisos.contains('*') || permisos.contains(permiso);

  /// Forma guardada en el almacén cifrado del dispositivo.
  Map<String, dynamic> aJson() => {
    'slug': slug,
    'bearer': bearer,
    'nombre': nombre,
    'rol': rol,
    'roles': roles,
    'roles_disponibles': rolesDisponibles.map((r) => r.aJson()).toList(),
    'permisos': permisos,
    'nombre_pila': nombrePila,
    'primer_apellido': primerApellido,
    'segundo_apellido': segundoApellido,
    'email': email,
    'email_pendiente': emailPendiente,
    'foto_url': fotoUrl,
    'tiene_contrasena': tieneContrasena,
    'google_conectado': googleConectado,
    'modalidad': modalidad.name,
    'terminologia': terminologia.aJson(),
    'estudio_nombre': estudioNombre,
    'perfil': perfil,
  };

  /// Restaura una sesión guardada (null si le falta lo esencial).
  static Sesion? desdeAlmacen(Map<String, dynamic> datos) {
    final slug = datos['slug'];
    final bearer = datos['bearer'];
    if (slug is! String ||
        bearer is! String ||
        slug.isEmpty ||
        bearer.isEmpty) {
      return null;
    }
    return Sesion(
      slug: slug,
      bearer: bearer,
      nombre: (datos['nombre'] ?? '') as String,
      rol: (datos['rol'] ?? '') as String,
      roles: _textos(datos['roles']),
      rolesDisponibles: RolDisponible.lista(datos['roles_disponibles']),
      permisos: _textos(datos['permisos']),
      nombrePila: datos['nombre_pila'] as String?,
      primerApellido: datos['primer_apellido'] as String?,
      segundoApellido: datos['segundo_apellido'] as String?,
      email: datos['email'] as String?,
      emailPendiente: datos['email_pendiente'] as String?,
      fotoUrl: datos['foto_url'] as String?,
      tieneContrasena: (datos['tiene_contrasena'] ?? true) as bool,
      googleConectado: (datos['google_conectado'] ?? false) as bool,
      modalidad: Modalidad.desde(datos['modalidad']),
      terminologia: Terminologia.desdeJson(
        datos['terminologia'] as Map<String, dynamic>?,
      ),
      estudioNombre: datos['estudio_nombre'] as String?,
      perfil: datos['perfil'] as String?,
    );
  }

  factory Sesion.desdeJson(
    String slug,
    String bearer,
    Map<String, dynamic> usuario, [
    Map<String, dynamic>? estudio,
  ]) {
    final config = estudio?['perfil_config'] as Map<String, dynamic>?;
    return Sesion(
      slug: slug,
      bearer: bearer,
      nombre: (usuario['nombre'] ?? '') as String,
      rol: (usuario['rol'] ?? '') as String,
      roles: _textos(usuario['roles']),
      rolesDisponibles: RolDisponible.lista(usuario['roles_disponibles']),
      permisos: _textos(usuario['permisos']),
      nombrePila: usuario['nombre_pila'] as String?,
      primerApellido: usuario['primer_apellido'] as String?,
      segundoApellido: usuario['segundo_apellido'] as String?,
      email: usuario['email'] as String?,
      emailPendiente: usuario['email_pendiente'] as String?,
      fotoUrl: usuario['foto_url'] as String?,
      tieneContrasena: (usuario['tiene_contrasena'] ?? true) as bool,
      googleConectado: (usuario['google_conectado'] ?? false) as bool,
      modalidad: Modalidad.desde(config?['modalidad']),
      terminologia: Terminologia.desdeJson(
        config?['terminologia'] as Map<String, dynamic>?,
      ),
      estudioNombre: estudio?['nombre'] as String?,
      perfil: estudio?['perfil'] as String?,
    );
  }

  /// La misma sesión con los datos de usuario que devuelve el servidor (p. ej. tras
  /// editar el perfil o cambiar de rol), conservando el estudio y la configuración
  /// del negocio.
  Sesion conUsuario(
    Map<String, dynamic> usuario, {
    bool? eligiendoRol,
  }) => Sesion(
    slug: slug,
    bearer: bearer,
    nombre: (usuario['nombre'] ?? nombre) as String,
    rol: (usuario['rol'] ?? rol) as String,
    roles: usuario.containsKey('roles') ? _textos(usuario['roles']) : roles,
    rolesDisponibles: usuario.containsKey('roles_disponibles')
        ? RolDisponible.lista(usuario['roles_disponibles'])
        : rolesDisponibles,
    eligiendoRol: eligiendoRol ?? this.eligiendoRol,
    permisos: usuario.containsKey('permisos')
        ? _textos(usuario['permisos'])
        : permisos,
    nombrePila: usuario['nombre_pila'] as String? ?? nombrePila,
    primerApellido: usuario.containsKey('primer_apellido')
        ? usuario['primer_apellido'] as String?
        : primerApellido,
    segundoApellido: usuario.containsKey('segundo_apellido')
        ? usuario['segundo_apellido'] as String?
        : segundoApellido,
    email: usuario['email'] as String? ?? email,
    emailPendiente: usuario.containsKey('email_pendiente')
        ? usuario['email_pendiente'] as String?
        : emailPendiente,
    fotoUrl: usuario.containsKey('foto_url')
        ? usuario['foto_url'] as String?
        : fotoUrl,
    tieneContrasena: (usuario['tiene_contrasena'] ?? tieneContrasena) as bool,
    googleConectado: (usuario['google_conectado'] ?? googleConectado) as bool,
    modalidad: modalidad,
    terminologia: terminologia,
    estudioNombre: estudioNombre,
    perfil: perfil,
  );

  static List<String> _textos(Object? valor) => valor is List
      ? valor.whereType<String>().toList(growable: false)
      : const [];
}
