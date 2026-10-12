import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:agendauno/features/auth/application/sesion_controller.dart';
import 'package:agendauno/features/auth/data/sesion.dart';
import 'package:agendauno/features/cuenta/data/cuenta_repository.dart';
import 'package:agendauno/features/cuenta/data/formularios.dart';
import 'package:agendauno/features/cuenta/presentation/formularios_cuenta.dart';

/// Las fichas de datos del negocio en la app, como en la web: se ven en el
/// Expediente con si ya se respondieron, y se llenan o corrigen con sus tipos de
/// campo; lo obligatorio se pide antes de mandar. Datos sintéticos.

final _ficha = FormulariosDeCuenta.desdeJson({
  'persona_id': 'per-1',
  'formularios': [
    {
      'id': 'f1',
      'nombre': 'Ficha de salud',
      'descripcion': 'Para cuidarte en clase.',
      'respondido_en': null,
      'campos': [
        {
          'id': 'c1',
          'etiqueta': 'Lesiones',
          'tipo': 'textarea',
          'obligatorio': true,
          'valor': null,
        },
        {
          'id': 'c2',
          'etiqueta': 'Edad',
          'tipo': 'numero',
          'obligatorio': false,
          'valor': null,
        },
        {
          'id': 'c3',
          'etiqueta': 'Embarazo',
          'tipo': 'booleano',
          'obligatorio': false,
          'valor': null,
        },
        {
          'id': 'c4',
          'etiqueta': 'Nivel',
          'tipo': 'seleccion',
          'obligatorio': false,
          'opciones': ['Básico', 'Avanzado'],
          'valor': 'Básico',
        },
      ],
    },
  ],
});

class _RepoFormularios extends CuentaRepository {
  _RepoFormularios() : super(Dio(), 'demo');

  final guardados = <Map<String, Object>>[];

  @override
  Future<FormulariosDeCuenta> formularios() async => _ficha;

  @override
  Future<void> responderFormulario(
    String formularioId,
    String personaId,
    Map<String, Object> valores,
  ) async => guardados.add({'f': formularioId, 'p': personaId, ...valores});
}

void main() {
  testWidgets('se llena una ficha: lo obligatorio primero', (tester) async {
    final repo = _RepoFormularios();
    tester.view.physicalSize = const Size(420, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sesionInicialProvider.overrideWithValue(
            const Sesion(
              slug: 'demo',
              bearer: 't',
              nombre: 'Ana',
              rol: 'miembro',
              permisos: ['formularios.responder'],
            ),
          ),
          cuentaRepositoryProvider.overrideWithValue(repo),
        ],
        child: const MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(child: FormulariosSeccion()),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Ficha de datos'), findsOneWidget);
    expect(find.text('Sin responder'), findsOneWidget);
    await tester.tap(find.text('Ficha de salud'));
    await tester.pumpAndSettle();
    expect(find.text('Para cuidarte en clase.'), findsOneWidget);

    // Sin lo obligatorio no se manda.
    await tester.tap(find.text('Guardar'));
    await tester.pumpAndSettle();
    expect(find.text('Este dato es obligatorio.'), findsOneWidget);
    expect(repo.guardados, isEmpty);

    await tester.enterText(find.byType(TextFormField).first, 'Rodilla');
    await tester.enterText(find.byType(TextFormField).last, '34');
    await tester.tap(find.byType(Switch));
    await tester.pump();
    await tester.tap(find.text('Guardar'));
    await tester.pumpAndSettle();

    expect(repo.guardados, [
      {
        'f': 'f1',
        'p': 'per-1',
        'c1': 'Rodilla',
        'c2': 34,
        'c3': true,
        'c4': 'Básico',
      },
    ]);
    expect(find.text('Respuestas guardadas.'), findsOneWidget);
  });

  test('sin perfil de cliente, no hay fichas que llenar', () {
    expect(FormulariosDeCuenta.vacio.formularios, isEmpty);
    final f = _ficha.formularios.single;
    expect(f.campos.map((c) => c.tipo), [
      'textarea',
      'numero',
      'booleano',
      'seleccion',
    ]);
    expect(f.campos.last.opciones, ['Básico', 'Avanzado']);
  });
}
