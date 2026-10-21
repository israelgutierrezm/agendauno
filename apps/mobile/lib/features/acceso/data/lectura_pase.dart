/// ¿Parece un pase de entrada? El QR de la app (`AU1.…`, vence en minutos) o el de
/// la cartera del teléfono (`AU2.…`). Lo demás que lea la cámara no se manda.
bool esPaseDeEntrada(String codigo) {
  final c = codigo.trim();
  return c.startsWith('AU1.') || c.startsWith('AU2.');
}

/// En qué quedó leer un pase en la entrada.
sealed class LecturaPase {
  const LecturaPase();
}

/// Se registró la entrada (`POST /accesos`): quién es, si pasa y por qué.
class EntradaRegistrada extends LecturaPase {
  const EntradaRegistrada({
    required this.permitido,
    required this.razon,
    this.nombre,
    this.personaId,
    this.registradoEn,
  });

  final bool permitido;

  /// El código estable de la decisión: ACCESS_BY_BOOKING, ACCESS_OPEN o
  /// NO_ACCESS.
  final String razon;
  final String? nombre;
  final String? personaId;
  final String? registradoEn;

  String get titulo => permitido ? 'Entrada permitida' : 'Entrada denegada';

  /// Por qué, como lo dice la recepción de la web.
  String get motivo => switch (razon) {
    'ACCESS_BY_BOOKING' => 'Tiene reserva para ahora.',
    'ACCESS_OPEN' => 'Su membresía le da acceso libre.',
    'NO_ACCESS' => 'No tiene reserva ni membresía con acceso libre.',
    _ => '',
  };

  factory EntradaRegistrada.desdeJson(Map<String, dynamic> j) {
    String? texto(Object? v) =>
        v is String && v.trim().isNotEmpty ? v.trim() : null;
    return EntradaRegistrada(
      permitido: j['permitido'] == true || j['resultado'] == 'permitido',
      razon: (j['codigo'] ?? '') as String,
      nombre: texto(j['persona_nombre']) ?? texto(j['persona']),
      personaId: texto(j['persona_id']),
      registradoEn: texto(j['registrado_en']),
    );
  }
}

/// No se registró: el código no es un pase, caducó, es de otro negocio, ya no
/// sirve, falta el permiso o no hubo conexión. [mensaje] dice cuál.
class PaseNoRegistrado extends LecturaPase {
  const PaseNoRegistrado(this.mensaje);

  final String mensaje;
}

/// Qué lectura de la cámara se atiende: una a la vez y, la misma, no otra vez
/// enseguida (la cámara lee el mismo código muchas veces por segundo; registrarlo
/// dos veces dejaría dos entradas en la bitácora).
class LectorDePase {
  LectorDePase({
    this.espera = const Duration(seconds: 10),
    DateTime Function()? reloj,
  }) : _reloj = reloj ?? DateTime.now;

  /// Cuánto se ignora el mismo código después de atenderlo.
  final Duration espera;
  final DateTime Function() _reloj;

  var _ocupado = false;
  String? _ultimo;
  DateTime? _atendido;

  /// ¿Se atiende [codigo]? Si sí, queda ocupado hasta [listo].
  bool tomar(String codigo) {
    final c = codigo.trim();
    if (c.isEmpty || _ocupado) {
      return false;
    }
    final atendido = _atendido;
    if (c == _ultimo &&
        atendido != null &&
        _reloj().difference(atendido) < espera) {
      return false;
    }
    _ocupado = true;
    _ultimo = c;
    return true;
  }

  /// Terminó de atender la lectura: desde ahora, la misma espera [espera].
  void listo() {
    _ocupado = false;
    _atendido = _reloj();
  }
}
