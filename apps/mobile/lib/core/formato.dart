import 'hora_negocio.dart';

/// Formatos de fecha y dinero en español, sin depender de `intl`. Las fechas se
/// muestran en la hora del negocio o de su sucursal (ver [HoraNegocio]), no en la
/// del teléfono.
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

  /// "jue 25 sep · 09:30" (o "—" si no hay fecha) en la hora del negocio: un
  /// instante del API se pasa a su zona con [desfaseMin] (el de la sesión); una
  /// hora local sin zona se muestra tal cual. Sin desfase (API anterior), la del
  /// teléfono.
  static String fechaHora(String? iso, {int? desfaseMin}) {
    final f = HoraNegocio.pared(iso, desfaseMin: desfaseMin);
    if (f == null) {
      return '—';
    }
    return diaHora(f);
  }

  /// "jue 25 sep · 09:30" tal cual (sin convertir de zona): p. ej. la hora de
  /// pared de una sucursal.
  static String diaHora(DateTime f) => '${dia(f)} · ${hora(f)}';

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

  /// Lo que se escribió como monto, en centavos y sin punto flotante (lo contrario
  /// de [dinero]): "1,299.50", "\$80" o "80.5". Null si no se entiende o se presta a
  /// confusión (antes que guardar un monto cien veces mayor o menor): más de dos
  /// decimales, miles mal agrupados («12,50»), letras entre los números o negativo
  /// sin [negativo].
  static int? aMinor(String texto, {bool negativo = false}) {
    var limpio = texto
        .replaceAll(RegExp(r'\s+'), '')
        .replaceFirst(RegExp(r'^[A-Za-z$]+'), '');
    var signo = 1;
    if (limpio.startsWith('-')) {
      if (!negativo) {
        return null;
      }
      signo = -1;
      limpio = limpio.substring(1).replaceFirst(RegExp(r'^[A-Za-z$]+'), '');
    }
    final forma = RegExp(r'^(\d{1,3}(,\d{3})+|\d*)(\.(\d{1,2}))?$');
    final m = forma.firstMatch(limpio);
    final entero = (m?.group(1) ?? '').replaceAll(',', '');
    final fraccion = m?.group(4) ?? '';
    // Sin ningún dígito (vacío, «.») o más grande de lo que acepta cualquier monto.
    if (m == null ||
        (entero.isEmpty && fraccion.isEmpty) ||
        entero.length > 12) {
      return null;
    }
    final centavos = int.parse(fraccion.padRight(2, '0'));
    return signo * ((entero.isEmpty ? 0 : int.parse(entero)) * 100 + centavos);
  }

  /// Créditos a partir de unidades (1000 = 1 crédito), exactos y sin punto
  /// flotante: "3", "0.5", "1.25", "-0.5". Nunca se redondean (0.5 no es «1»).
  static String creditos(int unidades) {
    final abs = unidades.abs();
    final resto = abs % 1000;
    final decimales = resto == 0
        ? ''
        : '.${resto.toString().padLeft(3, '0').replaceFirst(RegExp(r'0+$'), '')}';
    return '${unidades < 0 ? '-' : ''}${abs ~/ 1000}$decimales';
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
