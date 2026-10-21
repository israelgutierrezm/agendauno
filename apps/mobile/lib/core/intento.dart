import 'dart:convert';
import 'dart:math';

/// La clave de un intento (ADR 0201): la misma mientras se repite exactamente lo
/// mismo (se perdió la respuesta, otro toque: el servidor devuelve lo que ya hizo,
/// no lo hace otra vez) y otra en cuanto cambia lo que se pide. Tras una respuesta,
/// [listo]: lo siguiente es otro intento. Como `intentoUnico` en la web.
class IntentoUnico {
  static final _azar = Random.secure();

  String? _firma;
  String _clave = '';

  /// La clave para mandar [lo] (un mapa como el que va al API).
  String clave(Object? lo) {
    final firma = jsonEncode(lo);
    if (firma != _firma) {
      _firma = firma;
      _clave = List.generate(
        32,
        (_) => _azar.nextInt(16).toRadixString(16),
      ).join();
    }
    return _clave;
  }

  void listo() => _firma = null;
}
