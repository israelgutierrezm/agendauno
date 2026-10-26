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
    this.hayAnteriores = false,
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

  /// Los mensajes más recientes (al abrirla) y los anteriores que se cargaron.
  final List<MensajeBandeja> mensajes;

  /// Hay mensajes más viejos que los cargados («Cargar mensajes anteriores»).
  final bool hayAnteriores;

  /// Por WhatsApp, fuera del plazo de Meta, se contesta con una plantilla aprobada.
  bool get conPlantilla => canal == 'whatsapp' && !puedeContestar;

  /// La misma, con los mensajes anteriores delante.
  ConversacionBandeja conAnteriores(
    List<MensajeBandeja> anteriores, {
    required bool hayMas,
  }) => ConversacionBandeja(
    id: id,
    canal: canal,
    contacto: contacto,
    telefono: telefono,
    persona: persona,
    noLeidos: noLeidos,
    ultimoTexto: ultimoTexto,
    ultimoMensajeEn: ultimoMensajeEn,
    puedeContestar: puedeContestar,
    contestarHasta: contestarHasta,
    comoAgente: comoAgente,
    mensajes: [
      ...anteriores.where((m) => !mensajes.any((x) => x.id == m.id)),
      ...mensajes,
    ],
    hayAnteriores: hayMas,
  );

  /// Al volver a leerla (tras contestar), los anteriores que ya se cargaron se
  /// quedan, como en la web.
  ConversacionBandeja conservando(ConversacionBandeja? previa) {
    if (previa == null || previa.id != id || mensajes.isEmpty) {
      return this;
    }
    final desde = previa.mensajes.indexWhere((m) => m.id == mensajes.first.id);
    return desde > 0
        ? conAnteriores(
            previa.mensajes.sublist(0, desde),
            hayMas: previa.hayAnteriores,
          )
        : this;
  }

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
      hayAnteriores: j['hay_anteriores'] == true,
    );
  }
}

/// Una página de la lista de conversaciones (de 30 en 30, como la web).
class PaginaConversaciones {
  const PaginaConversaciones({
    required this.conversaciones,
    this.pagina = 1,
    this.ultimaPagina = 1,
  });

  final List<ConversacionBandeja> conversaciones;
  final int pagina;
  final int ultimaPagina;

  bool get hayMas => pagina < ultimaPagina;

  /// Las de esta y las de [siguiente], sin repetir (una conversación que subió
  /// mientras tanto no sale dos veces).
  PaginaConversaciones mas(PaginaConversaciones siguiente) {
    final vistas = conversaciones.map((c) => c.id).toSet();
    return PaginaConversaciones(
      conversaciones: [
        ...conversaciones,
        ...siguiente.conversaciones.where((c) => !vistas.contains(c.id)),
      ],
      pagina: siguiente.pagina,
      ultimaPagina: siguiente.ultimaPagina,
    );
  }
}

/// Una plantilla aprobada del WhatsApp del negocio (`GET /bandeja/plantillas`): lo
/// único que deja mandar Meta fuera de las 24 horas. Sus datos ({{1}}, {{2}}…) se
/// llenan antes de mandarla.
class PlantillaWhatsApp {
  const PlantillaWhatsApp({
    required this.nombre,
    required this.idioma,
    required this.cuerpo,
    this.categoria = '',
    this.encabezado,
    this.pie,
    this.variables = 0,
  });

  final String nombre;
  final String idioma;
  final String categoria;
  final String? encabezado;
  final String cuerpo;
  final String? pie;

  /// Cuántos datos pide.
  final int variables;

  String get clave => '$nombre|$idioma';

  String get categoriaTexto => switch (categoria) {
    'utility' => 'Utilidad',
    'marketing' => 'Marketing',
    'authentication' => 'Autenticación',
    _ => categoria,
  };

  /// Un dato que Meta acepta: no vacío, sin saltos de línea ni tabuladores ni más
  /// de 4 espacios seguidos.
  static bool datoValido(String v) =>
      v.trim().isNotEmpty && !RegExp(r'[\n\t]| {5,}').hasMatch(v);

  /// Cómo le llega: con los datos escritos en su lugar.
  String vistaPrevia(List<String> valores) {
    final texto = cuerpo.replaceAllMapped(RegExp(r'\{\{(\d+)\}\}'), (m) {
      final i = int.parse(m.group(1)!) - 1;
      final valor = i >= 0 && i < valores.length ? valores[i].trim() : '';
      return valor.isEmpty ? m.group(0)! : valor;
    });
    return [?encabezado, texto, ?pie].join('\n\n');
  }

  factory PlantillaWhatsApp.desdeJson(Map<String, dynamic> j) =>
      PlantillaWhatsApp(
        nombre: (j['nombre'] ?? '') as String,
        idioma: (j['idioma'] ?? '') as String,
        categoria: (j['categoria'] ?? '') as String,
        encabezado: _textoNoVacio(j['encabezado']),
        cuerpo: (j['cuerpo'] ?? '') as String,
        pie: _textoNoVacio(j['pie']),
        variables: (j['variables'] as num? ?? 0).toInt(),
      );
}

String? _textoNoVacio(Object? v) =>
    v is String && v.trim().isNotEmpty ? v : null;

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

  /// Las conversaciones abiertas, de 30 en 30, la más reciente primero.
  Future<PaginaConversaciones> conversaciones({
    String? q,
    int pagina = 1,
  }) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/conversaciones',
      queryParameters: {
        if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
        'page': pagina,
      },
    );
    final meta = (res.data?['meta'] as Map<String, dynamic>?) ?? const {};
    return PaginaConversaciones(
      conversaciones: ((res.data?['data'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(ConversacionBandeja.desdeJson)
          .toList(),
      pagina: (meta['page'] as num? ?? pagina).toInt(),
      ultimaPagina: (meta['ultima_pagina'] as num? ?? pagina).toInt(),
    );
  }

  /// La tanda de mensajes que va antes del mensaje [antes] (el primero cargado).
  Future<({List<MensajeBandeja> mensajes, bool hayAnteriores})> anteriores(
    String id,
    String antes,
  ) async {
    final res = await _dio.get<Map<String, dynamic>>(
      '$_base/conversaciones/$id/mensajes',
      queryParameters: {'antes': antes},
    );
    final meta = (res.data?['meta'] as Map<String, dynamic>?) ?? const {};
    return (
      mensajes: ((res.data?['data'] ?? const []) as List)
          .whereType<Map<String, dynamic>>()
          .map(MensajeBandeja.desdeJson)
          .toList(),
      hayAnteriores: meta['hay_anteriores'] == true,
    );
  }

  /// Las plantillas aprobadas del WhatsApp del negocio.
  Future<List<PlantillaWhatsApp>> plantillas() async {
    final res = await _dio.get<Map<String, dynamic>>('$_base/plantillas');
    return ((res.data?['data'] ?? const []) as List)
        .whereType<Map<String, dynamic>>()
        .map(PlantillaWhatsApp.desdeJson)
        .where((p) => p.nombre.isNotEmpty)
        .toList();
  }

  /// Contesta con una plantilla aprobada (también fuera de las 24 horas); Meta la
  /// cobra a la cuenta de WhatsApp del negocio.
  Future<void> mandarPlantilla(
    String id,
    PlantillaWhatsApp p,
    List<String> valores,
  ) => _dio.post<Map<String, dynamic>>(
    '$_base/conversaciones/$id/plantilla',
    data: {
      'nombre': p.nombre,
      'idioma': p.idioma,
      'variables': [for (final v in valores) v.trim()],
    },
  );

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

/// Las conversaciones abiertas, la más reciente primero, de página en página
/// («Ver más»).
class ConversacionesController extends AsyncNotifier<PaginaConversaciones> {
  @override
  Future<PaginaConversaciones> build() async {
    final repo = ref.watch(bandejaRepositoryProvider);
    return repo == null
        ? const PaginaConversaciones(conversaciones: [])
        : repo.conversaciones();
  }

  var _cargandoMas = false;

  /// La página siguiente, debajo de las que ya se ven. Si falla, se queda lo que
  /// había (y se puede volver a intentar).
  Future<void> cargarMas() async {
    final actual = state.value;
    final repo = ref.read(bandejaRepositoryProvider);
    if (actual == null || !actual.hayMas || repo == null || _cargandoMas) {
      return;
    }
    _cargandoMas = true;
    try {
      final siguiente = await repo.conversaciones(pagina: actual.pagina + 1);
      if (ref.mounted) {
        state = AsyncData(actual.mas(siguiente));
      }
    } finally {
      _cargandoMas = false;
    }
  }
}

final conversacionesProvider =
    AsyncNotifierProvider.autoDispose<
      ConversacionesController,
      PaginaConversaciones
    >(ConversacionesController.new);
