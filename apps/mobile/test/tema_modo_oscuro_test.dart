import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/theme/tema_agendauno.dart';

/// La app tiene un solo diseño, en claro y en oscuro: el modo lo decide el teléfono.
/// Los temas a elegir son solo de la web.
double _contraste(Color a, Color b) {
  final la = a.computeLuminance();
  final lb = b.computeLuminance();
  return (math.max(la, lb) + 0.05) / (math.min(la, lb) + 0.05);
}

Widget _app(ValueChanged<ColoresAgendaUno> leer) => MaterialApp(
  theme: TemaAgendaUno.claro(),
  darkTheme: TemaAgendaUno.oscuro(),
  themeMode: ThemeMode.system,
  home: Builder(
    builder: (context) {
      leer(context.colores);
      return const SizedBox();
    },
  ),
);

void main() {
  testWidgets('sigue el modo del teléfono: claro u oscuro', (tester) async {
    late ColoresAgendaUno colores;

    tester.platformDispatcher.platformBrightnessTestValue = Brightness.light;
    await tester.pumpWidget(_app((c) => colores = c));
    expect(colores.esOscuro, isFalse);
    expect(colores.fondo, ColoresAgendaUno.claros.fondo);

    tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
    await tester.pumpAndSettle();
    expect(colores.esOscuro, isTrue);
    expect(colores.fondo, ColoresAgendaUno.oscuros.fondo);

    addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
  });

  test('en los dos modos la letra se lee (AA) sobre fondos y botones', () {
    for (final c in [ColoresAgendaUno.claros, ColoresAgendaUno.oscuros]) {
      final modo = c.esOscuro ? 'oscuro' : 'claro';
      for (final (nombre, letra, fondo) in [
        ('texto/superficie', c.texto, c.superficie),
        ('texto/fondo', c.texto, c.fondo),
        ('suave/superficie', c.textoSuave, c.superficie),
        ('botón', c.acentoContraste, c.acento),
        ('enlace/superficie', c.acento, c.superficie),
        ('error/superficie', c.error, c.superficie),
        ('aviso/superficie', c.aviso, c.superficie),
        ('éxito/superficie', c.exito, c.superficie),
      ]) {
        expect(
          _contraste(letra, fondo),
          greaterThanOrEqualTo(4.5),
          reason: '$modo: $nombre',
        );
      }
    }
  });

  test('en oscuro, los pares de los estados aclaran su tinta y se leen', () {
    const fondoClaro = Color(0xFFE3F5EB);
    const tintaClara = Color(0xFF0F6B3E);
    expect(
      ColoresAgendaUno.claros.par(fondoClaro, tintaClara),
      (fondoClaro, tintaClara),
    );
    final (fondo, tinta) = ColoresAgendaUno.oscuros.par(fondoClaro, tintaClara);
    expect(_contraste(tinta, fondo), greaterThanOrEqualTo(4.5));
    expect(fondo.computeLuminance(), lessThan(0.1));
  });
}
