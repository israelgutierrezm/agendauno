/// Formatos de fecha en español sin depender de `intl` (hora local del dispositivo).
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

  /// "2026-09-25".
  static String iso(DateTime f) => '${f.year}-${_dos(f.month)}-${_dos(f.day)}';

  /// "\$1,299.00" a partir de centavos (sin punto flotante).
  static String dinero(int minor) {
    final pesos = (minor ~/ 100).toString().replaceAllMapped(
      RegExp(r'(\d)(?=(\d{3})+$)'),
      (m) => '${m[1]},',
    );
    return '\$$pesos.${_dos(minor % 100)}';
  }

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
