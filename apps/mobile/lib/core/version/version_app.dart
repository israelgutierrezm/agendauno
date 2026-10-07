/// La versión de esta app: la de `version:` en pubspec.yaml, sin el número de
/// compilación (lo que va después de «+»). Se compara con la mínima que acepta el
/// servidor (`app.version_minima` de /yo, ADR 0104). Al subirla en pubspec.yaml se
/// sube aquí; una prueba vigila que coincidan.
const versionApp = '1.0.0';

/// Sin versión mínima del servidor: no se exige ninguna.
const versionMinimaDesconocida = '0.0.0';

/// ¿`version` es más vieja que `minima`? Compara número por número (1.10.0 es más
/// nueva que 1.9.0; las partes que faltan valen 0) y no cuenta lo que va después
/// de «+» o «-». Una mínima que no se entiende no bloquea la app.
bool esMasVieja(String version, String minima) {
  final propia = _partes(version);
  final exigida = _partes(minima);
  if (propia == null || exigida == null) {
    return false;
  }
  final largo = propia.length > exigida.length ? propia.length : exigida.length;
  for (var i = 0; i < largo; i++) {
    final a = i < propia.length ? propia[i] : 0;
    final b = i < exigida.length ? exigida[i] : 0;
    if (a != b) {
      return a < b;
    }
  }
  return false;
}

/// [1, 10, 0] de "1.10.0+7"; null si no son números.
List<int>? _partes(String version) {
  final base = version.trim().split(RegExp(r'[+-]')).first;
  if (base.isEmpty) {
    return null;
  }
  final partes = <int>[];
  for (final parte in base.split('.')) {
    final n = int.tryParse(parte);
    if (n == null || n < 0) {
      return null;
    }
    partes.add(n);
  }
  return partes;
}
