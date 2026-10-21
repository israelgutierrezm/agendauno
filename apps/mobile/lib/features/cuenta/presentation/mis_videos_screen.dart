import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_repository.dart';
import '../data/videos.dart';
import 'cuenta_widgets.dart';

/// La biblioteca de videos del negocio (ADR 0184), solo en clases. Se vuelve a leer
/// con la cuenta: al comprar un plan, se ven los que incluye.
final videosProvider = FutureProvider.autoDispose<List<VideoMiembro>>((
  ref,
) async {
  await ref.watch(cuentaProvider.future);
  final repo = ref.watch(cuentaRepositoryProvider);
  final clases = ref.watch(sesionProvider)?.capacidades.clases ?? false;
  if (repo == null || !clases) {
    return const [];
  }
  return repo.videos();
});

/// «Videos» (como en la web): los del negocio por categoría. Los que incluye su plan
/// se abren (YouTube, Vimeo u otro sitio, en su app o en el navegador); de los demás,
/// el título y qué plan los incluye, con un atajo a los planes.
class MisVideosScreen extends ConsumerWidget {
  const MisVideosScreen({super.key, this.onVerPlanes});

  /// Lleva a Pagos para comprar un plan que los incluya.
  final VoidCallback? onVerPlanes;

  Future<void> _abrir(BuildContext context, VideoMiembro v) async {
    final messenger = ScaffoldMessenger.of(context);
    final abierto = await launchUrl(
      Uri.parse(v.url!),
      mode: LaunchMode.externalApplication,
    );
    if (!abierto) {
      messenger.showSnackBar(
        const SnackBar(content: Text('No se pudo abrir el video.')),
      );
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final estado = ref.watch(videosProvider);
    final estudio = ref.watch(sesionProvider)?.estudioNombre;

    return Scaffold(
      appBar: AppBar(title: const Text('Videos')),
      body: estado.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => ErrorDeCarga(
          mensaje: 'No se pudieron cargar los videos.',
          onReintentar: () => ref.invalidate(videosProvider),
        ),
        data: (videos) => RefreshIndicator(
          onRefresh: () => ref.refresh(videosProvider.future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            children: [
              Text(
                estudio == null || estudio.isEmpty
                    ? 'Clases grabadas y rutinas del negocio.'
                    : 'Clases grabadas y rutinas de $estudio.',
                style: TextStyle(color: context.colores.textoSuave),
              ),
              if (videos.isEmpty)
                const TextoVacio('El negocio aún no publica videos.'),
              for (final (categoria, lista) in VideoMiembro.porCategoria(
                videos,
              )) ...[
                if (categoria.isNotEmpty) TituloSeccion(categoria),
                if (categoria.isEmpty) const SizedBox(height: 12),
                for (final v in lista)
                  Card(
                    key: ValueKey('video-${v.id}'),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            v.titulo,
                            style: const TextStyle(fontWeight: FontWeight.w600),
                          ),
                          if (v.duracionMinutos != null)
                            Text(
                              '${v.duracionMinutos} min',
                              style: TextStyle(
                                fontSize: 13,
                                color: context.colores.textoSuave,
                              ),
                            ),
                          if (v.descripcion != null &&
                              v.descripcion!.trim().isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(top: 4),
                              child: Text(
                                v.descripcion!,
                                style: TextStyle(
                                  color: context.colores.textoSuave,
                                ),
                              ),
                            ),
                          const SizedBox(height: 8),
                          if (v.sePuedeVer)
                            FilledButton.tonal(
                              key: Key('ver-video-${v.id}'),
                              onPressed: () => _abrir(context, v),
                              child: const Text('Ver video'),
                            )
                          else
                            Wrap(
                              crossAxisAlignment: WrapCrossAlignment.center,
                              children: [
                                Text(
                                  v.comoTenerlo,
                                  style: TextStyle(
                                    color: context.colores.textoSuave,
                                  ),
                                ),
                                if (onVerPlanes != null)
                                  TextButton(
                                    onPressed: () {
                                      Navigator.of(context).pop();
                                      onVerPlanes!();
                                    },
                                    child: const Text('Ver planes'),
                                  ),
                              ],
                            ),
                        ],
                      ),
                    ),
                  ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
