import 'package:flutter/material.dart';

import '../../perfil/presentation/perfil_screen.dart';
import 'cuenta_widgets.dart';
import 'mi_privacidad_screen.dart';

/// Configuración del portal: su perfil (foto, contraseña, calendario del teléfono,
/// cerrar sesión) y su privacidad.
class ConfiguracionTab extends StatelessWidget {
  const ConfiguracionTab({super.key});

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
    children: [
      const TituloSeccion('Mi cuenta'),
      Card(
        child: ListTile(
          leading: const Icon(Icons.person_outline),
          title: const Text('Mi perfil'),
          subtitle: const Text(
            'Foto, nombre, contraseña y tus reservas en el calendario del teléfono',
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
            MaterialPageRoute<void>(builder: (_) => const MiPrivacidadScreen()),
          ),
        ),
      ),
    ],
  );
}
