import '../../../core/formato.dart';

/// Lo que además de las reservas trae la lista de una clase
/// (GET /sesiones/{id}/reservas → `meta`), como en el pase de lista de la web: los
/// invitados de los clientes (ADR 0182) y quien reservó desde Wellhub, TotalPass o
/// Fitpass (ADR 0202). No son clientes, pero ocupan lugar: el cupo de la lista los
/// cuenta, igual que el servidor.

/// Un invitado de un cliente (con un pase de su plan): ocupa lugar a nombre de quien
/// invita y se le marca si llegó (`PUT /invitados/{id}/asistencia`).
class InvitadoClase {
  const InvitadoClase({
    required this.id,
    required this.nombre,
    this.anfitrion,
    this.estado = 'reservado',
  });

  final String id;
  final String nombre;

  /// El cliente que lo invitó.
  final String? anfitrion;

  /// `reservado` (por marcar), `asistio` o `no_asistio`.
  final String estado;

  /// Ocupa lugar mientras está reservado o si llegó (como cuenta el servidor).
  bool get ocupaLugar => estado == 'reservado' || estado == 'asistio';
  bool get llego => estado == 'asistio';

  String get estadoTexto => switch (estado) {
    'asistio' => 'Llegó',
    'no_asistio' => 'No vino',
    _ => 'Por marcar',
  };

  InvitadoClase conEstado(String nuevo) => InvitadoClase(
    id: id,
    nombre: nombre,
    anfitrion: anfitrion,
    estado: nuevo,
  );

  factory InvitadoClase.desdeJson(Map<String, dynamic> j) => InvitadoClase(
    id: (j['id'] ?? '') as String,
    nombre: _texto(j['nombre']) ?? 'Invitado',
    anfitrion: _texto(j['anfitrion']),
    estado: (j['estado'] ?? 'reservado') as String,
  );

  static List<InvitadoClase> lista(Object? valor) => valor is List
      ? valor
            .whereType<Map<String, dynamic>>()
            .map(InvitadoClase.desdeJson)
            .where((i) => i.id.isNotEmpty)
            .toList(growable: false)
      : const [];
}

/// Quien reservó desde la app de una plataforma (Wellhub, TotalPass o Fitpass): un
/// visitante, no un cliente. Recepción marca que llegó (`/lugares-plataforma/{id}/
/// llego`; TotalPass lo valida en su propia app) o cancela su reserva (`…/cancelar`).
class LugarPlataforma {
  const LugarPlataforma({
    required this.id,
    required this.proveedor,
    this.nombre,
    this.estado = 'reservado',
    this.puedeMarcar = false,
  });

  final String id;

  /// `wellhub`, `totalpass` o `fitpass`.
  final String proveedor;
  final String? nombre;

  /// `reservado`, `asistio` o `no_asistio`.
  final String estado;

  /// Se marca aquí que llegó (TotalPass no: hace check-in en su app). Lo dice el
  /// servidor.
  final bool puedeMarcar;

  bool get ocupaLugar => estado == 'reservado' || estado == 'asistio';
  bool get llego => estado == 'asistio';
  bool get porMarcar => estado == 'reservado';

  /// Fitpass pide revisar la identificación oficial de quien llega.
  bool get pideIdentificacion => proveedor == 'fitpass';

  String get nombreVisible => nombre ?? 'Visitante';

  String get proveedorNombre => switch (proveedor) {
    'wellhub' => 'Wellhub',
    'totalpass' => 'TotalPass',
    'fitpass' => 'Fitpass',
    _ => proveedor,
  };

  String get estadoTexto => switch (estado) {
    'asistio' => 'Llegó',
    'no_asistio' => 'No vino',
    _ => 'Por marcar',
  };

  LugarPlataforma llegado() => LugarPlataforma(
    id: id,
    proveedor: proveedor,
    nombre: nombre,
    estado: 'asistio',
  );

  factory LugarPlataforma.desdeJson(Map<String, dynamic> j) => LugarPlataforma(
    id: (j['id'] ?? '') as String,
    proveedor: (j['proveedor'] ?? '') as String,
    nombre: _texto(j['nombre']),
    estado: (j['estado'] ?? 'reservado') as String,
    puedeMarcar: j['puede_marcar'] == true,
  );

  static List<LugarPlataforma> lista(Object? valor) => valor is List
      ? valor
            .whereType<Map<String, dynamic>>()
            .map(LugarPlataforma.desdeJson)
            .where((l) => l.id.isNotEmpty)
            .toList(growable: false)
      : const [];
}

/// Lo que un cliente apartó para recoger en la clase (ADR 0194): se cobra en el
/// mostrador al entregarlo (GET /sesiones/{id}/apartados).
class ApartadoClase {
  const ApartadoClase({
    required this.id,
    required this.articulo,
    required this.cantidad,
    required this.totalMinor,
    required this.moneda,
    this.personaId,
    this.persona,
  });

  final String id;
  final String articulo;
  final int cantidad;
  final int totalMinor;
  final String moneda;
  final String? personaId;
  final String? persona;

  /// «2 × Agua».
  String get texto => '$cantidad × $articulo';

  factory ApartadoClase.desdeJson(Map<String, dynamic> j) {
    final articulo = j['articulo'];
    final persona = j['persona'];
    return ApartadoClase(
      id: (j['id'] ?? '') as String,
      articulo: articulo is Map
          ? (_texto(articulo['nombre']) ?? 'Producto')
          : 'Producto',
      cantidad: (j['cantidad'] as num? ?? 1).toInt(),
      totalMinor: (j['total_minor'] as num? ?? 0).toInt(),
      moneda: Formato.moneda(j['moneda'], 'MXN'),
      personaId: persona is Map ? persona['id'] as String? : null,
      persona: persona is Map ? _texto(persona['nombre']) : null,
    );
  }

  static List<ApartadoClase> lista(Object? valor) => valor is List
      ? valor
            .whereType<Map<String, dynamic>>()
            .map(ApartadoClase.desdeJson)
            .toList(growable: false)
      : const [];

  /// Por persona, en el orden en que llegan (como la web).
  static List<List<ApartadoClase>> porPersona(List<ApartadoClase> apartados) {
    final grupos = <String, List<ApartadoClase>>{};
    for (final a in apartados) {
      grupos.putIfAbsent(a.personaId ?? '', () => []).add(a);
    }
    return grupos.values.toList(growable: false);
  }
}

String? _texto(Object? v) =>
    v is String && v.trim().isNotEmpty ? v.trim() : null;
