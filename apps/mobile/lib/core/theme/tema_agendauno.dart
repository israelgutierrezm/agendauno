import 'package:flutter/material.dart';

/// Tema claro de AgendaUno, el mismo de la web (tokens de `CatalogoTemas`):
/// fondo gris muy claro, superficies blancas con borde fino y acento azul de marca.
abstract final class TemaAgendaUno {
  static const acento = Color(0xFF0070FF);
  static const acentoSuave = Color(0xFFEAF1FF);
  static const fondo = Color(0xFFF6F7FB);
  static const superficie = Color(0xFFFFFFFF);
  static const borde = Color(0xFFE6E9F0);
  static const texto = Color(0xFF1E2A3B);
  static const textoSuave = Color(0xFF6B7385);
  static const exito = Color(0xFF12805C);
  static const aviso = Color(0xFFB54708);
  static const error = Color(0xFFB42318);

  static ThemeData claro() {
    final esquema = ColorScheme.fromSeed(
      seedColor: acento,
      primary: acento,
      surface: superficie,
      error: error,
    );
    final bordeFino = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(16),
      side: const BorderSide(color: borde),
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: esquema,
      scaffoldBackgroundColor: fondo,
      dividerColor: borde,
      appBarTheme: const AppBarTheme(
        backgroundColor: fondo,
        foregroundColor: texto,
        elevation: 0,
        scrolledUnderElevation: 0,
        titleTextStyle: TextStyle(
          color: texto,
          fontSize: 20,
          fontWeight: FontWeight.w300,
        ),
      ),
      cardTheme: CardThemeData(
        color: superficie,
        elevation: 0,
        margin: const EdgeInsets.only(bottom: 8),
        shape: bordeFino,
      ),
      listTileTheme: const ListTileThemeData(
        textColor: texto,
        iconColor: textoSuave,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: superficie,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: borde),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: borde),
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
          side: const BorderSide(color: borde),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),
      snackBarTheme: const SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
      ),
      textTheme: const TextTheme(
        titleMedium: TextStyle(color: texto, fontWeight: FontWeight.w600),
        bodyMedium: TextStyle(color: texto),
        bodySmall: TextStyle(color: textoSuave),
      ),
    );
  }
}
