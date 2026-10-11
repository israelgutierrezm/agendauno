import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';

/// Una persona a cargo de la cuenta (familias, ADR 0123) y el estado de su
/// autorización: `pendiente`, `activa` o `retirada`.
class Participante {
  const Participante({
    required this.tutelaId,
    required this.personaId,
    required this.nombre,
    required this.estado,
  });

  factory Participante.desdeJson(Map<String, dynamic> json) {
    final persona = (json['persona'] as Map?)?.cast<String, dynamic>() ?? {};
    return Participante(
      tutelaId: json['id'] as String,
      personaId: (persona['id'] ?? '') as String,
      nombre: (persona['nombre'] ?? '') as String,
      estado: (json['estado'] ?? 'pendiente') as String,
    );
  }

  final String tutelaId;
  final String personaId;
  final String nombre;
  final String estado;

  bool get autorizada => estado == 'activa';
}

/// Las personas a cargo y el texto de autorización vigente del negocio.
class PersonasACargo {
  const PersonasACargo({
    required this.lista,
    required this.version,
    required this.texto,
  });

  final List<Participante> lista;
  final int version;
  final String texto;

  List<Participante> get autorizadas =>
      lista.where((p) => p.autorizada).toList();
}

/// `/mi/participantes` de la cuenta misma (aquí nunca se actúa por otro).
class ParticipantesRepository {
  ParticipantesRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  String get _base => '/api/v1/app/$_slug/mi/participantes';

  Future<PersonasACargo> cargar() async {
    final res = await _dio.get<Map<String, dynamic>>(_base);
    final meta = (res.data?['meta'] as Map?)?.cast<String, dynamic>() ?? {};
    final lista = (res.data?['data'] as List? ?? const [])
        .whereType<Map<dynamic, dynamic>>()
        .map((m) => Participante.desdeJson(m.cast<String, dynamic>()))
        .where((p) => p.estado != 'retirada')
        .toList();
    return PersonasACargo(
      lista: lista,
      version: (meta['version'] as num?)?.toInt() ?? 1,
      texto: (meta['texto'] ?? '') as String,
    );
  }

  Future<void> autorizar(String tutelaId, int version) => _dio.post<void>(
    '$_base/$tutelaId/autorizar',
    data: {'acepto': true, 'version': version},
  );

  Future<void> retirar(String tutelaId) =>
      _dio.post<void>('$_base/$tutelaId/retirar');
}

/// Con el módulo de familias encendido en el negocio, las personas a su cargo
/// (null si no lo usa o no es alumno).
final personasACargoProvider = FutureProvider.autoDispose<PersonasACargo?>((
  ref,
) async {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null || !sesion.esMiembro || !sesion.tieneModulo('familias')) {
    return null;
  }
  return ParticipantesRepository(ref.watch(dioProvider), sesion.slug).cargar();
});

final participantesRepositoryProvider = Provider<ParticipantesRepository?>((
  ref,
) {
  final sesion = ref.watch(sesionProvider);
  return sesion == null
      ? null
      : ParticipantesRepository(ref.watch(dioProvider), sesion.slug);
});
