import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/dio_client.dart';
import '../../auth/application/sesion_controller.dart';

/// Google Calendar u Outlook conectado a su cuenta en las dos direcciones (ADR 0199,
/// `GET /yo/calendarios`): sus clases y citas aparecen en un calendario aparte y lo
/// ocupado de su calendario bloquea su agenda. Solo para quien atiende. Conectarlo
/// pide autorizar en Google o Microsoft: se hace en la web.
class CalendarioExterno {
  const CalendarioExterno({
    required this.proveedor,
    this.configurado = false,
    this.cuenta,
    this.estado,
  });

  /// `google` o `microsoft`.
  final String proveedor;

  /// La plataforma lo tiene listo para conectar.
  final bool configurado;

  /// La cuenta conectada (null: sin conectar).
  final String? cuenta;
  final String? estado;

  bool get conectado => estado != null;

  String get nombre => switch (proveedor) {
    'google' => 'Google Calendar',
    'microsoft' => 'Outlook',
    _ => proveedor,
  };

  static CalendarioExterno desdeJson(Map<String, dynamic> j) {
    final conexion = j['conexion'];
    return CalendarioExterno(
      proveedor: (j['proveedor'] ?? '') as String,
      configurado: j['configurado'] == true,
      cuenta: conexion is Map ? conexion['cuenta'] as String? : null,
      estado: conexion is Map ? (conexion['estado'] as String? ?? '') : null,
    );
  }
}

/// Si puede sincronizar sus calendarios y con cuáles (los listos o ya conectados).
/// Null si no aplica (no atiende, o no hay ninguno listo) o no se pudo saber.
final calendariosExternosProvider =
    FutureProvider.autoDispose<List<CalendarioExterno>?>((ref) async {
      final sesion = ref.watch(sesionProvider);
      if (sesion == null || sesion.esMiembro) {
        return null;
      }
      try {
        final res = await ref
            .read(dioProvider)
            .get<Map<String, dynamic>>(
              '/api/v1/app/${sesion.slug}/yo/calendarios',
            );
        final data = res.data?['data'];
        if (data is! Map || data['disponible'] != true) {
          return null;
        }
        return ((data['proveedores'] ?? const []) as List)
            .whereType<Map<String, dynamic>>()
            .map(CalendarioExterno.desdeJson)
            .where((c) => c.configurado || c.conectado)
            .toList();
      } on Object {
        return null;
      }
    });
