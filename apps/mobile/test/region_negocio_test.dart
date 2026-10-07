import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/core/google/google_auth.dart';
import 'package:agendauno/core/network/auth_token.dart';
import 'package:agendauno/core/network/dio_client.dart';
import 'package:agendauno/core/paises.dart';
import 'package:agendauno/core/storage/almacen_sesion.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/auth/presentation/login_screen.dart';
import 'package:agendauno/features/perfil/presentation/perfil_screen.dart';
import 'package:agendauno/main.dart';

/// El país y la lada del negocio llegan en la sesión: un celular sin «+» es de ese
/// país (el servidor le pone su lada), así que Mi perfil pide escribir con su lada
/// el de otro. Y la app está en español, también los selectores de fecha. Datos
/// sintéticos.

class _SinGoogleRegion implements GoogleAuth {
  @override
  bool get disponible => false;

  @override
  Future<String?> idToken() async => null;
}

/// Clienta de un negocio en Colombia; guarda el celular que se manda.
class _SesionEnColombia extends SesionController {
  String? celularGuardado;

  @override
  Sesion? build() => Sesion.desdeJson(
    'estudio-bogota',
    '1|x',
    {
      'nombre': 'Ana',
      'nombre_pila': 'Ana',
      'rol': 'miembro',
      'roles': ['miembro'],
      'tiene_ficha': true,
      'celular': '3001234567',
    },
    {'nombre': 'Estudio Bogotá', 'moneda': 'COP', 'pais': 'CO', 'lada': '57'},
  );

  @override
  Future<void> guardarPerfil({
    required String nombre,
    String? primerApellido,
    String? segundoApellido,
    String? celular,
    String? fechaNacimiento,
    String? genero,
    bool conCelular = false,
  }) async {
    celularGuardado = celular;
  }
}

/// Sin red: todo responde 404 (el login no necesita nada para mostrarse).
class _ApiVacia implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async => ResponseBody.fromString(
    jsonEncode({'message': 'No'}),
    404,
    headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    },
  );

  @override
  void close({bool force = false}) {}
}

void main() {
  group('la sesión sabe el país y la lada del negocio', () {
    test('los toma del estudio, normalizados', () {
      final s = Sesion.desdeJson(
        'demo',
        't',
        {'nombre': 'Ana', 'rol': 'miembro'},
        {'pais': 'co', 'lada': '+57'},
      );
      expect(s.pais, 'CO');
      expect(s.lada, '57');
    });

    test('sin país ni lada (o raros), los de México', () {
      final s = Sesion.desdeJson(
        'demo',
        't',
        {'nombre': 'Ana', 'rol': 'miembro'},
        {'pais': 'Colombia', 'lada': ''},
      );
      expect(s.pais, 'MX');
      expect(s.lada, '52');
      expect(
        Sesion.desdeJson('demo', 't', {'nombre': 'Ana', 'rol': 'miembro'}).lada,
        '52',
      );
    });

    test('se guardan en el teléfono y sobreviven a editar el perfil', () {
      final s = Sesion.desdeJson(
        'demo',
        't',
        {'nombre': 'Ana', 'rol': 'miembro'},
        {'pais': 'ES', 'lada': '34'},
      );
      final guardada = Sesion.desdeAlmacen(s.aJson())!;
      expect(guardada.pais, 'ES');
      expect(guardada.lada, '34');

      final editada = guardada.conUsuario({'nombre': 'Ana María'});
      expect(editada.pais, 'ES');
      expect(editada.lada, '34');

      // Una sesión guardada antes de esto (sin país) es de México.
      final antigua = Map<String, dynamic>.of(s.aJson())
        ..remove('pais')
        ..remove('lada');
      expect(Sesion.desdeAlmacen(antigua)!.pais, 'MX');
      expect(Sesion.desdeAlmacen(antigua)!.lada, '52');
    });
  });

  group('países', () {
    test('se nombran en español, como en la web', () {
      expect(Paises.nombre('MX'), 'México');
      expect(Paises.nombre('co'), 'Colombia');
      expect(Paises.nombre('ES'), 'España');
      expect(Paises.nombre('US'), 'Estados Unidos');
      expect(Paises.nombre('ZZ'), isNull);
      expect(Paises.nombre(null), isNull);
    });

    test('la ayuda del celular nombra el país y pone el ejemplo de otro', () {
      expect(
        Paises.ayudaCelular(pais: 'MX', lada: '52'),
        'Si no es de México, escríbelo con su lada, p. ej. +57 300 123 4567',
      );
      // En Colombia el ejemplo no puede ser de Colombia.
      expect(
        Paises.ayudaCelular(pais: 'CO', lada: '57'),
        'Si no es de Colombia, escríbelo con su lada, p. ej. +52 55 1234 5678',
      );
      expect(
        Paises.ayudaCelular(pais: 'ZZ', lada: '999'),
        'Si es de otro país, escríbelo con su lada, p. ej. +57 300 123 4567',
      );
    });
  });

  testWidgets('Mi perfil explica la lada y manda el celular con «+» tal cual', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(800, 2600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final sesion = _SesionEnColombia();
    final contenedor = ProviderContainer(
      overrides: [
        sesionProvider.overrideWith(() => sesion),
        googleAuthProvider.overrideWithValue(_SinGoogleRegion()),
      ],
    );
    addTearDown(contenedor.dispose);
    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: contenedor,
        child: const MaterialApp(home: PerfilScreen()),
      ),
    );

    expect(
      find.textContaining(
        'Si no es de Colombia, escríbelo con su lada, p. ej. +52 55 1234 5678',
      ),
      findsOneWidget,
    );

    await tester.enterText(find.byKey(const Key('celular')), '+52 5512345678');
    await tester.tap(find.text('Guardar'));
    await tester.pumpAndSettle();
    expect(sesion.celularGuardado, '+52 5512345678');
  });

  testWidgets('la app y su selector de fecha están en español', (tester) async {
    FlutterSecureStorage.setMockInitialValues({});
    final c = ProviderContainer(
      overrides: [
        almacenSesionProvider.overrideWithValue(AlmacenSesion()),
        tokenInicialProvider.overrideWithValue(null),
        sesionInicialProvider.overrideWithValue(null),
      ],
    );
    addTearDown(c.dispose);
    c.read(dioProvider).httpClientAdapter = _ApiVacia();
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      UncontrolledProviderScope(container: c, child: const AgendaUnoApp()),
    );
    await tester.pumpAndSettle();

    final contexto = tester.element(find.byType(LoginScreen));
    expect(Localizations.localeOf(contexto).languageCode, 'es');
    expect(MaterialLocalizations.of(contexto).cancelButtonLabel, 'Cancelar');

    showDatePicker(
      context: contexto,
      initialDate: DateTime(2026, 10, 1),
      firstDate: DateTime(2026),
      lastDate: DateTime(2027),
    );
    await tester.pumpAndSettle();
    expect(find.text('octubre de 2026'), findsOneWidget);
    expect(find.text('Seleccionar fecha'), findsOneWidget);
    expect(find.text('Cancelar'), findsOneWidget);
  });
}
