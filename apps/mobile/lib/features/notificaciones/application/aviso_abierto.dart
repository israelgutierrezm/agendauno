import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../auth/application/sesion_controller.dart';

/// A dónde lleva una notificación al tocarla, según de qué habla (`tipo` del push:
/// la clave de su plantilla, o `difusion`).
enum DestinoPush {
  /// Su reserva o su cita: la lista (cliente) o la agenda (equipo).
  reservas,

  /// Un cobro, un pago o su plan: Pagos.
  pagos,

  /// Lo demás (bienvenida, difusiones): el Inicio.
  inicio;

  static DestinoPush de(String? tipo) {
    final t = tipo ?? '';
    if (t.startsWith('reserva.')) {
      return reservas;
    }
    const dePagos = [
      'orden.',
      'pago.',
      'pago_automatico.',
      'cobro.',
      'membresia.',
    ];
    if (dePagos.any(t.startsWith)) {
      return pagos;
    }
    return inicio;
  }
}

/// La notificación que se tocó y aún no se atiende: la pantalla de la sesión la
/// toma, cambia de pestaña y la descarta. Una de otro negocio se ignora (la app
/// tiene una sola sesión).
class AvisoAbierto extends Notifier<DestinoPush?> {
  @override
  DestinoPush? build() => null;

  /// Llegó al tocar una notificación (con la app en segundo plano o cerrada).
  void abrir(Map<String, dynamic> datos) {
    final slug = ref.read(sesionProvider)?.slug;
    final estudio = datos['estudio'];
    if (slug == null || (estudio is String && estudio != slug)) {
      return;
    }
    state = DestinoPush.de(datos['tipo'] as String?);
  }

  void atendido() => state = null;
}

final avisoAbiertoProvider = NotifierProvider<AvisoAbierto, DestinoPush?>(
  AvisoAbierto.new,
);

/// Desde la pantalla de la sesión: al tocar una notificación (también la que abrió
/// la app), le pasa su destino y la descarta. Va después del cuadro en curso, no
/// mientras se construye.
void escucharAvisos(
  ConsumerState<ConsumerStatefulWidget> pantalla,
  void Function(DestinoPush destino) ir,
) {
  pantalla.ref.listenManual<DestinoPush?>(avisoAbiertoProvider, (_, destino) {
    if (destino == null) {
      return;
    }
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!pantalla.mounted) {
        return;
      }
      ir(destino);
      pantalla.ref.read(avisoAbiertoProvider.notifier).atendido();
    });
  }, fireImmediately: true);
}
