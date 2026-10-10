import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/config/app_config.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../../core/version/version_app.dart';
import '../application/sesion_controller.dart';

/// La página de la app en la tienda del teléfono: Google Play en Android y el App
/// Store en iOS (solo si se compiló con `APP_STORE_ID`). null si no hay a dónde
/// mandarla (iOS sin id, web o escritorio): entonces no se ofrece el botón.
Uri? urlTienda(
  TargetPlatform plataforma, {
  String idAndroid = AppConfig.idAndroid,
  String appStoreId = AppConfig.appStoreId,
}) => switch (plataforma) {
  TargetPlatform.android => Uri.parse(
    'https://play.google.com/store/apps/details?id=$idAndroid',
  ),
  TargetPlatform.iOS when appStoreId.trim().isNotEmpty => Uri.parse(
    'https://apps.apple.com/app/id${appStoreId.trim()}',
  ),
  _ => null,
};

/// Esta versión de la app ya no la acepta el servidor (`app.version_minima` de /yo,
/// ADR 0104): en lugar de leer respuestas que ya no entiende, pide actualizarla. No
/// deja seguir; solo abrir la tienda, volver a revisar (por si ya se actualizó) o
/// cerrar sesión.
class ActualizarAppScreen extends ConsumerStatefulWidget {
  const ActualizarAppScreen({super.key});

  @override
  ConsumerState<ActualizarAppScreen> createState() =>
      _ActualizarAppScreenState();
}

class _ActualizarAppScreenState extends ConsumerState<ActualizarAppScreen> {
  bool _revisando = false;

  Future<void> _revisar() async {
    setState(() => _revisando = true);
    await ref.read(sesionProvider.notifier).refrescar();
    if (mounted) {
      setState(() => _revisando = false);
    }
  }

  Future<void> _abrirTienda(Uri url) async {
    final messenger = ScaffoldMessenger.of(context);
    final abierta = await launchUrl(url, mode: LaunchMode.externalApplication);
    if (!abierta) {
      messenger.showSnackBar(
        const SnackBar(
          content: Text(
            'No se pudo abrir la tienda. Búscanos como AgendaUno en ella.',
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final minima = ref.watch(sesionProvider)?.versionMinima;
    final tienda = kIsWeb ? null : urlTienda(defaultTargetPlatform);
    return Scaffold(
      key: const Key('actualizar-app'),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 32, 20, 20),
          children: [
            Text(
              'Actualiza AgendaUno para continuar',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            const Text(
              'Esta versión de la app ya no es compatible. Descarga la más '
              'reciente desde la tienda de tu teléfono y vuelve a abrirla; tu '
              'sesión se conserva.',
              style: TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            const SizedBox(height: 12),
            Text(
              minima == null
                  ? 'Tienes la versión $versionApp.'
                  : 'Tienes la versión $versionApp; se necesita la $minima o '
                        'una más reciente.',
              style: const TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            const SizedBox(height: 20),
            if (tienda != null) ...[
              FilledButton(
                key: const Key('abrir-tienda'),
                onPressed: () => _abrirTienda(tienda),
                child: const Text('Actualizar en la tienda'),
              ),
              const SizedBox(height: 8),
            ],
            if (tienda != null)
              OutlinedButton(
                onPressed: _revisando ? null : _revisar,
                child: const Text('Volver a revisar'),
              )
            else
              FilledButton(
                onPressed: _revisando ? null : _revisar,
                child: const Text('Volver a revisar'),
              ),
            const SizedBox(height: 4),
            TextButton(
              onPressed: _revisando
                  ? null
                  : () => ref.read(sesionProvider.notifier).cerrar(),
              child: const Text('Cerrar sesión'),
            ),
          ],
        ),
      ),
    );
  }
}
