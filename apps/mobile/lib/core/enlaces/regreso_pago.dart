import 'package:flutter/widgets.dart';

import '../config/app_config.dart';

/// El regreso a la app después de pagar en el navegador: la página de la web ofrece
/// «Volver a la app» con `<esquema>://pagos?ref=<pago>&pago=exito|pendiente|cancelado`
/// (el esquema es el de cada app, [AppConfig.esquemaApp]; Android e iOS lo declaran
/// para esta app). La app abre Pagos y vuelve a leer la cuenta.
class RegresoDePago {
  const RegresoDePago({this.ref, this.estado});

  /// La referencia del pago (`ref_pago` de la pasarela).
  final String? ref;

  /// `exito`, `pendiente` o `cancelado` (lo que confirmó la web con el servidor).
  final String? estado;

  /// Lo que se le dice al volver.
  String get mensaje => switch (estado) {
    'exito' => 'Recibimos tu pago. En un momento se refleja en tu cuenta.',
    'pendiente' =>
      'Tu pago está en proceso. Se confirmará en cuanto la pasarela lo apruebe.',
    'cancelado' => 'El pago no se completó. Puedes intentarlo de nuevo.',
    _ => 'Actualizamos tu cuenta con lo último de tus pagos.',
  };

  /// El regreso del pago en [uri], o null si es otra cosa. Flutter lo entrega como
  /// la URL completa (`agendauno://pagos?ref=…`) o, según la plataforma, solo como
  /// ruta (`/pagos?ref=…` o `/?ref=…`): se aceptan las tres. Un esquema que no es el
  /// de esta app se ignora.
  static RegresoDePago? de(Uri uri, {String? esquema}) {
    final conEsquema = uri.scheme.isNotEmpty;
    if (conEsquema &&
        uri.scheme.toLowerCase() != (esquema ?? AppConfig.esquemaApp)) {
      return null;
    }
    final q = uri.queryParameters;
    final dePagos =
        uri.host == 'pagos' ||
        uri.pathSegments.contains('pagos') ||
        (!conEsquema && q.containsKey('ref'));
    if (!dePagos) {
      return null;
    }
    String? texto(String clave) {
      final v = q[clave]?.trim();
      return v == null || v.isEmpty ? null : v;
    }

    return RegresoDePago(ref: texto('ref'), estado: texto('pago'));
  }
}

/// Atiende los enlaces que abren la app (`WidgetsBindingObserver`): el regreso del
/// pago se toma aquí y no llega al Navigator (que no tiene esa ruta). Se registra
/// antes que el `MaterialApp` para ser el primero en verlo.
class EnlacesDeLaApp with WidgetsBindingObserver {
  EnlacesDeLaApp(this.alRegresarDePago);

  final void Function(RegresoDePago regreso) alRegresarDePago;

  @override
  Future<bool> didPushRouteInformation(
    RouteInformation routeInformation,
  ) async {
    final regreso = RegresoDePago.de(routeInformation.uri);
    if (regreso == null) {
      return false;
    }
    alRegresarDePago(regreso);
    return true;
  }

  /// El enlace con el que se abrió la app (cerrada), si fue el regreso del pago.
  static RegresoDePago? alAbrir() => RegresoDePago.de(
    Uri.tryParse(WidgetsBinding.instance.platformDispatcher.defaultRouteName) ??
        Uri(),
  );
}
