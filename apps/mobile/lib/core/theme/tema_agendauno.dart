import 'package:flutter/material.dart';

/// Los colores de la app: un juego claro (el mismo del tema AgendaUno de la web) y uno
/// oscuro que se usa cuando el teléfono está en modo oscuro. La app tiene un solo
/// diseño; los temas que cada quien elige son solo de la web.
///
/// Las pantallas los leen con `context.colores`: así cambian solas cuando el teléfono
/// pasa de claro a oscuro.
@immutable
class ColoresAgendaUno extends ThemeExtension<ColoresAgendaUno> {
  const ColoresAgendaUno({
    required this.esOscuro,
    required this.acento,
    required this.acentoContraste,
    required this.acentoSuave,
    required this.fondo,
    required this.superficie,
    required this.superficie2,
    required this.borde,
    required this.texto,
    required this.textoSuave,
    required this.exito,
    required this.aviso,
    required this.error,
  });

  final bool esOscuro;
  // El azul de la marca y la letra que va encima (4.5:1 o más en los dos).
  final Color acento;
  final Color acentoContraste;
  final Color acentoSuave;
  final Color fondo;
  final Color superficie;
  final Color superficie2;
  final Color borde;
  final Color texto;
  final Color textoSuave;
  final Color exito;
  final Color aviso;
  final Color error;

  /// Claro: fondo gris muy claro, superficies blancas con borde fino y el azul de la
  /// marca (#006DF7: con letra blanca, 4.6:1).
  static const claros = ColoresAgendaUno(
    esOscuro: false,
    acento: Color(0xFF006DF7),
    acentoContraste: Color(0xFFFFFFFF),
    acentoSuave: Color(0xFFEAF1FF),
    fondo: Color(0xFFF6F7FB),
    superficie: Color(0xFFFFFFFF),
    superficie2: Color(0xFFF1F3F8),
    borde: Color(0xFFE6E9F0),
    texto: Color(0xFF1E2A3B),
    textoSuave: Color(0xFF6B7385),
    exito: Color(0xFF12805C),
    aviso: Color(0xFFB54708),
    error: Color(0xFFB42318),
  );

  /// Oscuro: azul marino profundo, la letra casi blanca (14.6:1) y el azul de la marca
  /// más claro (#5EA2FF, 6.3:1 sobre las tarjetas) con letra oscura encima (7.1:1).
  static const oscuros = ColoresAgendaUno(
    esOscuro: true,
    acento: Color(0xFF5EA2FF),
    acentoContraste: Color(0xFF06132B),
    acentoSuave: Color(0xFF1C3354),
    fondo: Color(0xFF0B1426),
    superficie: Color(0xFF131F36),
    superficie2: Color(0xFF1B2944),
    borde: Color(0xFF2A3A57),
    texto: Color(0xFFEEF2F8),
    textoSuave: Color(0xFFA3B1C9),
    exito: Color(0xFF4CC38A),
    aviso: Color(0xFFF5A524),
    error: Color(0xFFFF6B61),
  );

  /// Un color de letra o de ícono pensado para fondo claro (un estado, un servicio):
  /// en oscuro se aclara para que se lea sobre las superficies oscuras.
  Color tinta(Color color) {
    if (!esOscuro) {
      return color;
    }
    final hsl = HSLColor.fromColor(color);
    return hsl
        .withLightness(hsl.lightness < 0.72 ? 0.72 : hsl.lightness)
        .withSaturation(hsl.saturation > 0.85 ? 0.85 : hsl.saturation)
        .toColor();
  }

  /// Un par fondo + tinta pensado para claro (p. ej. el de un estado o un servicio):
  /// en oscuro, la tinta se aclara y el fondo es esa tinta, suave, sobre la superficie.
  (Color, Color) par(Color fondoClaro, Color tintaClara) {
    if (!esOscuro) {
      return (fondoClaro, tintaClara);
    }
    final clara = tinta(tintaClara);
    return (Color.alphaBlend(clara.withValues(alpha: 0.16), superficie), clara);
  }

  /// Los mismos colores con el de una marca en lugar del azul (la app de marca
  /// blanca, ADR 0208). Como el azul, debe leerse sobre las superficies y con su
  /// letra encima (4.5:1 o más): en claro se oscurece y en oscuro se aclara lo que
  /// haga falta.
  ColoresAgendaUno conMarca(Color marca) {
    final opaca = marca.withValues(alpha: 1);
    final acentoMarca = _legible(opaca, superficie, aclarar: esOscuro);
    return ColoresAgendaUno(
      esOscuro: esOscuro,
      acento: acentoMarca,
      acentoContraste: acentoContraste,
      acentoSuave: Color.alphaBlend(
        acentoMarca.withValues(alpha: esOscuro ? 0.22 : 0.10),
        superficie,
      ),
      fondo: fondo,
      superficie: superficie,
      superficie2: superficie2,
      borde: borde,
      texto: texto,
      textoSuave: textoSuave,
      exito: exito,
      aviso: aviso,
      error: error,
    );
  }

  /// [color] con la luz justa para leerse sobre [fondo] (4.5:1): lo aclara o lo
  /// oscurece poco a poco, sin cambiar su tono.
  static Color _legible(Color color, Color fondo, {required bool aclarar}) {
    var legible = color;
    var hsl = HSLColor.fromColor(color);
    for (var i = 0; i < 50 && contraste(legible, fondo) < 4.5; i++) {
      final luz = hsl.lightness + (aclarar ? 0.02 : -0.02);
      hsl = hsl.withLightness(luz.clamp(0.0, 1.0));
      legible = hsl.toColor();
    }
    return legible;
  }

  /// El contraste entre dos colores (WCAG: de 1 a 21).
  static double contraste(Color a, Color b) {
    final la = a.computeLuminance();
    final lb = b.computeLuminance();
    return la > lb ? (la + 0.05) / (lb + 0.05) : (lb + 0.05) / (la + 0.05);
  }

  /// Un color `#RRGGBB` (o `#RGB`, con o sin `#`); otro valor, null.
  static Color? colorDe(String? hex) {
    var valor = (hex ?? '').trim().replaceFirst('#', '');
    if (RegExp(r'^[0-9a-fA-F]{3}$').hasMatch(valor)) {
      valor = valor.split('').map((c) => '$c$c').join();
    }
    if (!RegExp(r'^[0-9a-fA-F]{6}$').hasMatch(valor)) {
      return null;
    }
    return Color(0xFF000000 | int.parse(valor, radix: 16));
  }

  @override
  ColoresAgendaUno copyWith({bool? esOscuro}) =>
      esOscuro == null || esOscuro == this.esOscuro
      ? this
      : (esOscuro ? oscuros : claros);

  @override
  ColoresAgendaUno lerp(ColoresAgendaUno? other, double t) {
    if (other == null) {
      return this;
    }
    Color mezcla(Color a, Color b) => Color.lerp(a, b, t)!;
    return ColoresAgendaUno(
      esOscuro: t < 0.5 ? esOscuro : other.esOscuro,
      acento: mezcla(acento, other.acento),
      acentoContraste: mezcla(acentoContraste, other.acentoContraste),
      acentoSuave: mezcla(acentoSuave, other.acentoSuave),
      fondo: mezcla(fondo, other.fondo),
      superficie: mezcla(superficie, other.superficie),
      superficie2: mezcla(superficie2, other.superficie2),
      borde: mezcla(borde, other.borde),
      texto: mezcla(texto, other.texto),
      textoSuave: mezcla(textoSuave, other.textoSuave),
      exito: mezcla(exito, other.exito),
      aviso: mezcla(aviso, other.aviso),
      error: mezcla(error, other.error),
    );
  }
}

/// `context.colores`: los colores del modo en que está el teléfono.
extension ColoresDelContexto on BuildContext {
  ColoresAgendaUno get colores =>
      Theme.of(this).extension<ColoresAgendaUno>() ?? ColoresAgendaUno.claros;
}

/// El tema de la app, claro y oscuro, con el mismo diseño. Con [marca] (la app de
/// marca blanca), su color en lugar del azul.
abstract final class TemaAgendaUno {
  static ThemeData claro({Color? marca}) => _tema(
    marca == null
        ? ColoresAgendaUno.claros
        : ColoresAgendaUno.claros.conMarca(marca),
  );
  static ThemeData oscuro({Color? marca}) => _tema(
    marca == null
        ? ColoresAgendaUno.oscuros
        : ColoresAgendaUno.oscuros.conMarca(marca),
  );

  static ThemeData _tema(ColoresAgendaUno c) {
    final brillo = c.esOscuro ? Brightness.dark : Brightness.light;
    final base = ColorScheme.fromSeed(
      seedColor: c.acento,
      brightness: brillo,
      primary: c.acento,
      onPrimary: c.acentoContraste,
      surface: c.superficie,
      onSurface: c.texto,
      error: c.error,
    );
    // En oscuro, hojas, diálogos y menús en las mismas superficies que las tarjetas
    // (sin los tonos que Material saca de la semilla).
    final esquema = c.esOscuro
        ? base.copyWith(
            onSurfaceVariant: c.textoSuave,
            outline: c.borde,
            outlineVariant: c.borde,
            surfaceContainerLowest: c.fondo,
            surfaceContainerLow: c.superficie,
            surfaceContainer: c.superficie,
            surfaceContainerHigh: c.superficie2,
            surfaceContainerHighest: c.superficie2,
            secondaryContainer: c.acentoSuave,
            onSecondaryContainer: c.texto,
          )
        : base;
    final bordeFino = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(16),
      side: BorderSide(color: c.borde),
    );

    return ThemeData(
      useMaterial3: true,
      brightness: brillo,
      colorScheme: esquema,
      extensions: [c],
      scaffoldBackgroundColor: c.fondo,
      dividerColor: c.borde,
      appBarTheme: AppBarTheme(
        backgroundColor: c.fondo,
        foregroundColor: c.texto,
        elevation: 0,
        scrolledUnderElevation: 0,
        titleTextStyle: TextStyle(
          color: c.texto,
          fontSize: 20,
          fontWeight: FontWeight.w300,
        ),
      ),
      cardTheme: CardThemeData(
        color: c.superficie,
        elevation: 0,
        margin: const EdgeInsets.only(bottom: 8),
        shape: bordeFino,
      ),
      listTileTheme: ListTileThemeData(
        textColor: c.texto,
        iconColor: c.textoSuave,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: c.superficie,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: c.borde),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: c.borde),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          side: BorderSide(color: c.borde),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: c.superficie,
        modalBackgroundColor: c.superficie,
      ),
      dialogTheme: DialogThemeData(backgroundColor: c.superficie),
      snackBarTheme: const SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
      ),
      textTheme: TextTheme(
        titleMedium: TextStyle(color: c.texto, fontWeight: FontWeight.w600),
        bodyMedium: TextStyle(color: c.texto),
        bodySmall: TextStyle(color: c.textoSuave),
      ),
    );
  }
}
