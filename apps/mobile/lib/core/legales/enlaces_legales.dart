import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config/app_config.dart';
import '../config/producto_app.dart';
import '../theme/tema_agendauno.dart';

/// Un documento legal que se abre en la web.
class DocumentoLegal {
  const DocumentoLegal(this.titulo, this.url);

  final String titulo;
  final Uri url;

  /// Los de la plataforma, en la web de esta app (`/aviso-de-privacidad` y
  /// `/terminos`) y, con [negocio] (su dirección), el aviso que ese negocio publica
  /// para sus clientes (`/estudio/<negocio>/aviso-de-privacidad`; si aún no lo
  /// publica, la página lo dice).
  static List<DocumentoLegal> de({String? negocio, String? nombreNegocio}) {
    final producto = ProductoApp.actual.nombre;
    final slug = negocio?.trim() ?? '';
    final nombre = nombreNegocio?.trim() ?? '';
    return [
      DocumentoLegal(
        'Aviso de privacidad de $producto',
        AppConfig.urlWeb('/aviso-de-privacidad'),
      ),
      DocumentoLegal(
        'Términos y condiciones',
        AppConfig.urlWeb('/terminos'),
      ),
      if (slug.isNotEmpty)
        DocumentoLegal(
          nombre.isEmpty
              ? 'Aviso de privacidad del negocio'
              : 'Aviso de privacidad de $nombre',
          AppConfig.urlWeb(
            '/estudio/${Uri.encodeComponent(slug)}/aviso-de-privacidad',
          ),
        ),
    ];
  }
}

/// Los enlaces al aviso de privacidad y a los términos (las tiendas piden que se
/// lleguen desde la app): en el acceso, en el perfil y en la cuenta del cliente,
/// que además ve el aviso de su negocio.
class EnlacesLegales extends StatelessWidget {
  const EnlacesLegales({super.key, this.negocio, this.nombreNegocio});

  /// La dirección del negocio cuyo aviso se ofrece (el del cliente o el de la app
  /// de marca blanca); null, solo los de la plataforma.
  final String? negocio;
  final String? nombreNegocio;

  @override
  Widget build(BuildContext context) {
    final documentos = DocumentoLegal.de(
      negocio: negocio,
      nombreNegocio: nombreNegocio,
    );
    return Wrap(
      key: const Key('enlaces-legales'),
      alignment: WrapAlignment.center,
      children: [
        for (final d in documentos)
          TextButton(
            style: TextButton.styleFrom(
              foregroundColor: context.colores.textoSuave,
              textStyle: const TextStyle(fontSize: 13),
            ),
            onPressed: () => _abrir(context, d.url),
            child: Text(d.titulo, textAlign: TextAlign.center),
          ),
      ],
    );
  }

  Future<void> _abrir(BuildContext context, Uri url) async {
    final mensajero = ScaffoldMessenger.maybeOf(context);
    final abierto = await launchUrl(url, mode: LaunchMode.externalApplication)
        .catchError((_) => false);
    if (!abierto) {
      mensajero?.showSnackBar(
        SnackBar(content: Text('No pudimos abrir $url')),
      );
    }
  }
}
