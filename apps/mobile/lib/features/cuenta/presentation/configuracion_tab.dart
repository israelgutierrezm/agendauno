import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/legales/enlaces_legales.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../perfil/presentation/perfil_screen.dart';
import 'cuenta_widgets.dart';
import 'mi_privacidad_screen.dart';

/// Configuración del portal: su perfil (foto, contraseña, calendario del teléfono,
/// cerrar sesión), su privacidad y, al final, los avisos de privacidad (el de la
/// plataforma y el de su negocio) y los términos.
class ConfiguracionTab extends ConsumerWidget {
  const ConfiguracionTab({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    // Sus clases o sus citas, según el negocio (no las dos).
    final sesiones = (sesion?.terminologia ?? const Terminologia()).sesiones
        .toLowerCase();
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
      children: [
        const TituloSeccion('Mi cuenta'),
        Card(
          child: ListTile(
            leading: const Icon(Icons.person_outline),
            title: const Text('Mi perfil'),
            subtitle: Text(
              'Foto, nombre, contraseña y tus $sesiones en el calendario del '
              'teléfono',
            ),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => Navigator.of(
              context,
            ).push(MaterialPageRoute<void>(builder: (_) => const PerfilScreen())),
          ),
        ),
        Card(
          child: ListTile(
            leading: const Icon(Icons.privacy_tip_outlined),
            title: const Text('Privacidad y mis datos'),
            subtitle: const Text('Promociones, tus datos y su baja'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => const MiPrivacidadScreen(),
              ),
            ),
          ),
        ),
        const SizedBox(height: 8),
        EnlacesLegales(
          negocio: sesion?.slug,
          nombreNegocio: sesion?.estudioNombre,
        ),
      ],
    );
  }
}
