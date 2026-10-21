/// Un video de la biblioteca del negocio (ADR 0184), como lo da `GET /mi/videos`: los
/// publicados, y el enlace solo de los que incluye uno de sus planes vigentes (de los
/// demás, el título y qué plan los incluye).
class VideoMiembro {
  const VideoMiembro({
    required this.id,
    required this.titulo,
    this.descripcion,
    this.url,
    this.duracionMinutos,
    this.categoria,
    this.planes = const [],
    this.disponible = false,
  });

  final String id;
  final String titulo;
  final String? descripcion;

  /// El enlace (YouTube, Vimeo u otro sitio); null si su plan no lo incluye.
  final String? url;
  final int? duracionMinutos;
  final String? categoria;

  /// Los planes que lo incluyen (vacío: cualquier plan vigente).
  final List<String> planes;

  /// ¿Su plan lo incluye?
  final bool disponible;

  /// Se abre solo si lo tiene incluido y el enlace es https (como lo guarda el
  /// negocio; nunca otro esquema).
  bool get sePuedeVer =>
      disponible && url != null && Uri.tryParse(url!)?.scheme == 'https';

  /// «Incluido en: Mensual, Pack 8.» o, sin planes, «con un plan vigente».
  String get comoTenerlo => planes.isEmpty
      ? 'Disponible con un plan vigente.'
      : 'Incluido en: ${planes.join(', ')}.';

  factory VideoMiembro.desdeJson(Map<String, dynamic> j) => VideoMiembro(
    id: (j['id'] ?? '') as String,
    titulo: (j['titulo'] ?? '') as String,
    descripcion: j['descripcion'] as String?,
    url: j['url'] as String?,
    duracionMinutos: (j['duracion_minutos'] as num?)?.toInt(),
    categoria: j['categoria'] as String?,
    planes: ((j['planes'] ?? const []) as List)
        .whereType<Map<String, dynamic>>()
        .map((p) => (p['nombre'] ?? '') as String)
        .where((n) => n.isNotEmpty)
        .toList(),
    disponible: j['disponible'] == true,
  );

  /// Por categoría, en el orden en que llegan; los que no tienen, al final (como en
  /// la web).
  static List<(String, List<VideoMiembro>)> porCategoria(
    List<VideoMiembro> videos,
  ) {
    final grupos = <String, List<VideoMiembro>>{};
    for (final v in videos) {
      grupos.putIfAbsent(v.categoria?.trim() ?? '', () => []).add(v);
    }
    return [
      for (final e in grupos.entries)
        if (e.key.isNotEmpty) (e.key, e.value),
      if (grupos.containsKey('')) ('', grupos['']!),
    ];
  }
}
