import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/network/mensaje_error.dart';
import '../application/sesion_controller.dart';

/// Lo que se hace en la web (no en la app), como lo nombra la web: autorizar la
/// tarjeta con el formulario de la pasarela (OpenPay) y conectar Google Calendar u
/// Outlook. Se abren con [abrirEnLaWeb].
abstract final class DestinosWeb {
  /// Mis pagos, en su sección de pago automático.
  static const pagoAutomatico = '/mi-cuenta/pagos#pago-automatico';

  /// Mi perfil, en Preferencias (ahí se conectan Google Calendar y Outlook).
  static const calendarios = '/mi-perfil#mp-preferencias';
}

/// Abre una URL en el navegador del teléfono (fuera de la app). Se sobrescribe en
/// las pruebas.
final abrirFueraProvider = Provider<Future<bool> Function(Uri)>(
  (ref) =>
      (url) => launchUrl(url, mode: LaunchMode.externalApplication),
);

/// «Abrir en la web»: pide al servidor un enlace de un solo uso (vale 2 minutos)
/// que abre la web con esta misma sesión en [destino], sin volver a escribir la
/// contraseña, y lo abre en el navegador. Si no se pudo, lo dice. Devuelve si se
/// abrió.
Future<bool> abrirEnLaWeb(
  BuildContext context,
  WidgetRef ref,
  String destino,
) async {
  final mensajero = ScaffoldMessenger.of(context);
  try {
    final url = await ref.read(sesionProvider.notifier).enlaceWeb(destino);
    final abierto = url != null && await ref.read(abrirFueraProvider)(url);
    if (!abierto) {
      mensajero.showSnackBar(
        const SnackBar(content: Text('No se pudo abrir la web.')),
      );
    }
    return abierto;
  } on DioException catch (e) {
    mensajero.showSnackBar(
      SnackBar(
        content: Text(
          mensajeDeError(e, porDefecto: 'No se pudo abrir la web.'),
        ),
      ),
    );
    return false;
  }
}
