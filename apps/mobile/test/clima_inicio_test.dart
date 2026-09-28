import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/cuenta_models.dart';
import 'package:agendauno/features/cuenta/presentation/tarjeta_principal.dart';

/// El clima y la foto del Inicio del alumno: qué dice cada clima y de dónde sale la
/// foto; la sesión recuerda el nombre del negocio y su giro.
void main() {
  test('el clima dice para qué y dónde', () {
    final pronostico = ClimaMiembro.desdeJson({
      'tipo': 'pronostico',
      'lugar': 'Roma Norte',
      'aproximado': false,
      'temperatura': 16,
      'condicion': 'Lluvia',
      'icono': 'lluvia',
      'es_de_dia': true,
      'lluvia': 70,
    });
    final ahora = ClimaMiembro.desdeJson({
      'tipo': 'ahora',
      'lugar': 'Guadalajara',
      'aproximado': true,
      'temperatura': 24.4,
      'condicion': 'Despejado',
      'icono': 'despejado',
      'es_de_dia': false,
      'lluvia': null,
    });

    expect(
      pronostico.dondeTexto('cita'),
      'Pronóstico para tu cita en Roma Norte',
    );
    expect(ahora.dondeTexto('clase'), 'Ahora cerca de Guadalajara');
    expect(ahora.temperatura, 24);
    expect(ahora.lluvia, isNull);
    // De noche, el sol se vuelve luna.
    expect(iconoClima('despejado', deDia: false), Icons.nightlight_outlined);
    expect(iconoClima('algo-nuevo'), Icons.cloud_outlined);
  });

  test('la foto sale del giro del negocio; sin giro conocido, la general', () {
    expect(
      fotoNegocio('pole'),
      endsWith('/assets/landing/disciplinas/pole-v1.jpg'),
    );
    expect(
      fotoNegocio(null),
      endsWith('/assets/landing/disciplinas/wellness-v1.webp'),
    );
  });

  test('la sesión recuerda el nombre del negocio y su giro', () {
    final s = Sesion.desdeJson(
      'demo',
      '1|abc',
      {'nombre': 'Vale', 'rol': 'miembro'},
      {'nombre': 'Estudio Demo', 'perfil': 'pole'},
    );
    final restaurada = Sesion.desdeAlmacen(s.aJson())!;

    expect(restaurada.estudioNombre, 'Estudio Demo');
    expect(restaurada.perfil, 'pole');
    expect(restaurada.conUsuario({'nombre': 'Vale R.'}).perfil, 'pole');
  });
}
