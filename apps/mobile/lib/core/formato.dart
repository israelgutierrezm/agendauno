/// Formatos de fecha (hora local del dispositivo) y dinero en español, sin depender
/// de `intl`.
abstract final class Formato {
  static const _dias = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
  static const _meses = [
    'ene',
    'feb',
    'mar',
    'abr',
    'may',
    'jun',
    'jul',
    'ago',
    'sep',
    'oct',
    'nov',
    'dic',
  ];

  /// "jue 25 sep · 09:30" (o "—" si no hay fecha).
  static String fechaHora(String? iso) {
    final f = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
    if (f == null) {
      return '—';
    }
    return '${_dias[f.weekday - 1]} ${f.day} ${_meses[f.month - 1]} · ${hora(f)}';
  }

  /// "09:30".
  static String hora(DateTime f) => '${_dos(f.hour)}:${_dos(f.minute)}';

  /// "jue 25 sep".
  static String dia(DateTime f) =>
      '${_dias[f.weekday - 1]} ${f.day} ${_meses[f.month - 1]}';

  /// "14 nov" a partir de "2026-11-14" (o "—").
  static String diaMes(String? fecha) {
    final f = fecha == null ? null : DateTime.tryParse(fecha);
    if (f == null) {
      return '—';
    }
    return '${f.day} ${_meses[f.month - 1]}';
  }

  /// "2026-09-25".
  static String iso(DateTime f) => '${f.year}-${_dos(f.month)}-${_dos(f.day)}';

  /// Dinero en la moneda del negocio a partir de centavos, sin punto flotante y
  /// como la web: "\$1,299.00" en pesos mexicanos y el código en las demás
  /// ("EUR 1,299.00", "COP 25,000.00"). Todas las monedas del catálogo tienen dos
  /// decimales (ADR 0097).
  static String dinero(int minor, String moneda) {
    final abs = minor.abs();
    final enteros = (abs ~/ 100).toString().replaceAllMapped(
      RegExp(r'(\d)(?=(\d{3})+$)'),
      (m) => '${m[1]},',
    );
    final codigo = moneda.trim().toUpperCase();
    // Espacio duro: el código no se separa de la cantidad al cortar la línea.
    final prefijo = codigo.isEmpty || codigo == 'MXN' ? '\$' : '$codigo ';
    return '${minor < 0 ? '-' : ''}$prefijo$enteros.${_dos(abs % 100)}';
  }

  /// La moneda (ISO 4217) que trae una respuesta del API o, si no la trae, la del
  /// negocio ([respaldo], la de la sesión).
  static String moneda(Object? valor, String respaldo) =>
      valor is String && valor.trim().isNotEmpty
      ? valor.trim().toUpperCase()
      : respaldo;

  /// "24 de octubre" a partir de "2026-10-24".
  static String fechaLarga(String? fecha) {
    final f = fecha == null ? null : DateTime.tryParse(fecha);
    if (f == null) {
      return '—';
    }
    return '${f.day} de ${_mesesLargos[f.month - 1]}';
  }

  static const _mesesLargos = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
  ];

  static String _dos(int n) => n.toString().padLeft(2, '0');
}
