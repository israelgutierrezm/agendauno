import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/punto_que_late.dart';
import '../../auth/application/sesion_controller.dart';
import 'perfil_screen.dart';

/// El botón «Mi perfil» de la barra de arriba (equipo, quien imparte y clientes).
/// Ahí está «Cambiar de rol»: si la persona puede entrar con otro rol, lleva el punto
/// que late, el mismo que en la web.
class BotonMiPerfil extends ConsumerWidget {
  const BotonMiPerfil({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final variosRoles = ref.watch(sesionProvider)?.tieneVariosRoles ?? false;
    const icono = Icon(Icons.person_outline);
    return IconButton(
      key: const Key('boton-mi-perfil'),
      tooltip: variosRoles ? 'Mi perfil (puedes cambiar de rol)' : 'Mi perfil',
      icon: variosRoles
          ? const Stack(
              clipBehavior: Clip.none,
              children: [
                icono,
                Positioned(
                  top: -7,
                  right: -9,
                  child: PuntoQueLate(key: Key('punto-rol-perfil')),
                ),
              ],
            )
          : icono,
      onPressed: () => Navigator.of(
        context,
      ).push(MaterialPageRoute<void>(builder: (_) => const PerfilScreen())),
    );
  }
}
