/// Los lugares numerados de una clase (B06), como los da
/// `GET /mi/sesiones/{sesion}/lugares`: cuántos tiene, cuáles están tomados y el
/// suyo, si ya eligió.
class LugaresDeClase {
  const LugaresDeClase({
    required this.total,
    this.ocupados = const [],
    this.mio,
  });

  final int total;

  /// Los tomados (incluido el suyo).
  final List<int> ocupados;
  final int? mio;

  /// ¿Lo puede elegir? Libre o el suyo.
  bool libre(int lugar) => lugar == mio || !ocupados.contains(lugar);

  /// null si la clase no numera sus lugares (`data: null`).
  static LugaresDeClase? desdeJson(Object? data) {
    if (data is! Map<String, dynamic>) {
      return null;
    }
    final total = (data['total'] as num? ?? 0).toInt();
    if (total <= 0) {
      return null;
    }
    return LugaresDeClase(
      total: total,
      ocupados: ((data['ocupados'] ?? const []) as List)
          .whereType<num>()
          .map((n) => n.toInt())
          .toList(),
      mio: (data['mio'] as num?)?.toInt(),
    );
  }
}
