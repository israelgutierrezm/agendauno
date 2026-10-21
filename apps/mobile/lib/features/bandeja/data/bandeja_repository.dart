import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';

/// Una conversación de la bandeja (WhatsApp, Instagram o Messenger; ADR 0200).
class ConversacionBandeja {
  const ConversacionBandeja({
    required this.id,
    required this.canal,
    required this.contacto,
    required this.telefono,
    required this.persona,
    required this.noLeidos,
    required this.ultimoTexto,
    required this.ultimoMensajeEn,
    required this.puedeContestar,
    required this.contestarHasta,
    required this.comoAgente,
    this.mensajes = const [],
  });

  final String id;
  final String canal;
  final String? contacto;
  final String? telefono;
  final String? persona;
  final int noLeidos;
  final String? ultimoTexto;
  final String? ultimoMensajeEn;
  final bool puedeContestar;
  final String? contestarHasta;

  /// Instagram o Messenger después de 24 horas: contesta una persona del equipo.
  final bool comoAgente;
  final List<MensajeBandeja> mensajes;

  /// Quién escribe: su ficha, su nombre en la red o su número.
  String get titulo => persona ?? contacto ?? telefono ?? 'Sin nombre';

  factory ConversacionBandeja.desdeJson(Map<String, dynamic> j) {
    final persona = j['persona'];
    return ConversacionBandeja(
      id: (j['id'] ?? '') as String,
      canal: (j['canal'] ?? '') as String,
      contacto: j['contacto'] as String?,
      telefono: j['telefono'] as String?,
      persona: persona is Map ? persona['nombre'] as String? : null,
      noLeidos: (j['no_leidos'] as num?)?.toInt() ?? 0,
      ultimoTexto: j['ultimo_texto'] as String?,
      ultimoMensajeEn: j['ultimo_mensaje_en'] as String?,
      puedeContestar: j['puede_contestar'] == true,
      contestarHasta: j['contestar_hasta'] as String?,
      comoAgente: j['como_agente'] == true,
      mensajes: ((j['mensajes'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(MensajeBandeja.desdeJson)
          .toList(),
    );
  }
}

/// Un mensaje de una conversación: entrante (de la persona) o saliente (del equipo).
class MensajeBandeja {
  const MensajeBandeja({
    required this.id,
    required this.entrante,
    required this.tipo,
    required this.texto,
    required this.estado,
    required this.enviadoEn,
    required this.enviadoPor,
    required this.archivo,
  });

  final String id;
  final bool entrante;
  final String tipo;
  final String? texto;
  final String? estado;
  final String? enviadoEn;
  final String? enviadoPor;
  final ArchivoBandeja? archivo;

  factory MensajeBandeja.desdeJson(Map<String, dynamic> j) {
    final archivo = j['archivo'];
    return MensajeBandeja(
      id: (j['id'] ?? '') as String,
      entrante: j['direccion'] == 'entrante',
      tipo: (j['tipo'] ?? 'texto') as String,
      texto: j['texto'] as String?,
      estado: j['estado'] as String?,
      enviadoEn: j['enviado_en'] as String?,
      enviadoPor: j['enviado_por'] as String?,
      archivo: archivo is Map<String, dynamic>
          ? ArchivoBandeja.desdeJson(archivo)
          : null,
    );
  }
}

/// El archivo de un mensaje: se pide aparte con la sesión.
class ArchivoBandeja {
  const ArchivoBandeja({
    required this.nombre,
    required this.mime,
    required this.listo,
    required this.enLinea,
  });

  final String? nombre;
  final String? mime;

  /// Ya está guardado en el negocio.
  final bool listo;

  /// Se ve en la app (imagen); lo demás se abre fuera.
  final bool enLinea;

  factory ArchivoBandeja.desdeJson(Map<String, dynamic> j) => ArchivoBandeja(
    nombre: j['nombre'] as String?,
    mime: j['mime'] as String?,
    listo: j['listo'] == true,
    enLinea: j['en_linea'] == true,
  );
}

/// La bandeja del negocio (`/bandeja/*`): quien atiende ve las conversaciones y
/// contesta dentro de lo que deja Meta (ADR 0200).
class BandejaRepository {
  BandejaRepository(this._dio, this._slug);

  final Dio _dio;
  final String _slug;

  String get _base => '/api/v1/app/$_slug/bandeja';

  Future<List<ConversacionBandeja>> conversaciones({String? q}) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/conversaciones',
      queryParameters: {if (q != null && q.trim().isNotEmpty) 'q': q.trim()},
    );
    return ((res.data?['data'] ?? const []) as List)
        .whereType<Map<String, dynamic>>()
        .map(ConversacionBandeja.desdeJson)
        .toList();
  }

  /// La conversación con sus mensajes (abrirla la deja leída).
  Future<ConversacionBandeja> conversacion(String id) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/conversaciones/$id',
    );
    return ConversacionBandeja.desdeJson(
      (res.data?['data'] ?? const <String, dynamic>{}) as Map<String, dynamic>,
    );
  }

  Future<void> contestar(String id, String texto) =>
      _dio.post<Map<String, dynamic>>(
        '$_base/conversaciones/$id/mensajes',
        data: {'texto': texto},
      );

  /// Manda una foto (con pie opcional).
  Future<void> mandarFoto(
    String id,
    List<int> bytes,
    String nombre, {
    String? pie,
  }) => _dio.post<Map<String, dynamic>>(
    '$_base/conversaciones/$id/archivos',
    data: FormData.fromMap({
      'archivo': MultipartFile.fromBytes(bytes, filename: nombre),
      if (pie != null && pie.trim().isNotEmpty) 'texto': pie.trim(),
    }),
  );

  /// Los bytes del archivo de un mensaje.
  Future<Uint8List> archivo(String mensajeId) async {
    final res = await _dio.get<List<int>>(
      '$_base/mensajes/$mensajeId/archivo',
      options: Options(responseType: ResponseType.bytes),
    );
    return Uint8List.fromList(res.data ?? const []);
  }

  Future<void> archivar(String id) => _dio.put<Map<String, dynamic>>(
    '$_base/conversaciones/$id',
    data: {'estado': 'archivada'},
  );
}

final bandejaRepositoryProvider = Provider<BandejaRepository?>((ref) {
  final sesion = ref.watch(sesionProvider);
  if (sesion == null) {
    return null;
  }
  return BandejaRepository(ref.watch(dioProvider), sesion.slug);
});

/// Las conversaciones abiertas, la más reciente primero.
final conversacionesProvider =
    FutureProvider.autoDispose<List<ConversacionBandeja>>((ref) async {
      final repo = ref.watch(bandejaRepositoryProvider);
      return repo == null ? const [] : repo.conversaciones();
    });
