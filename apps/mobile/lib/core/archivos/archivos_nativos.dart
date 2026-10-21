import 'package:flutter/services.dart';

/// Un archivo que la app bajó con su sesión: las rutas del API piden el bearer, así
/// que no se abren con un enlace en el navegador (como en la web, que arma un blob).
class ArchivoDescargado {
  const ArchivoDescargado({required this.bytes, this.mime});

  final Uint8List bytes;

  /// El tipo (`image/jpeg`, `application/pdf`…), si se sabe.
  final String? mime;

  /// Una foto se muestra dentro de la app; lo demás (un PDF) con el visor del
  /// teléfono.
  bool get esImagen => mime?.startsWith('image/') ?? false;

  /// El tipo que dijo el servidor o, si no lo dijo (o dijo uno genérico), el de la
  /// extensión del nombre. Los documentos del negocio son JPG, PNG o PDF.
  static String? tipoDe(String? contentType, String? nombre) {
    final dicho = contentType?.split(';').first.trim().toLowerCase();
    if (dicho != null &&
        dicho.isNotEmpty &&
        dicho != 'application/octet-stream') {
      return dicho;
    }
    final extension = (nombre ?? '').split('.').last.toLowerCase();
    return switch (extension) {
      'jpg' || 'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'pdf' => 'application/pdf',
      _ => null,
    };
  }
}

/// Lo que la app le pide al teléfono con esos bytes (canal `agendauno/archivos`; en
/// Android `MainActivity`, en iPhone `AppDelegate`): abrir un documento con su visor
/// y agregar el pase de entrada a Apple Wallet (ADR 0195). Si el teléfono no puede,
/// devuelve false (nunca lanza): quien llama lo dice.
abstract final class ArchivosNativos {
  static const canal = MethodChannel('agendauno/archivos');

  /// Lo abre con el visor del teléfono (un PDF, por ejemplo).
  static Future<bool> abrir(
    ArchivoDescargado archivo, {
    required String nombre,
  }) => _pedir('abrir', {
    'bytes': archivo.bytes,
    'nombre': nombre,
    'mime': archivo.mime,
  });

  /// Muestra la hoja de Apple Wallet para agregar el pase (`.pkpass`). Solo en
  /// iPhone.
  static Future<bool> agregarPaseWallet(Uint8List pkpass) =>
      _pedir('agregarPaseWallet', {'bytes': pkpass});

  static Future<bool> _pedir(String metodo, Map<String, Object?> datos) async {
    try {
      return await canal.invokeMethod<bool>(metodo, datos) ?? false;
    } on PlatformException {
      return false;
    } on MissingPluginException {
      return false;
    }
  }
}
