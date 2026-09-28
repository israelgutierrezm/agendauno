import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../application/sesion_controller.dart';
import '../data/sesion.dart';

/// Nombre de un rol como lo ve la persona (con la terminología del negocio: en una
/// barbería, «Barbero» y «Cliente»).
String nombreDeRol(RolDisponible rol, Terminologia t) =>
    rol.nombre ??
    switch (rol.clave) {
      'propietario' => 'Dueño',
      'admin' => 'Administrador',
      'recepcionista' => 'Recepción',
      'instructor' => t.instructor,
      'miembro' => t.miembro,
      _ => rol.clave,
    };

/// Qué verá con ese rol.
String detalleDeFaceta(String faceta, Terminologia t) => switch (faceta) {
  'instructor' =>
    'Tus ${t.sesiones.toLowerCase()}, tu agenda y la asistencia de tus '
        '${t.miembros.toLowerCase()}.',
  'miembro' => 'Tus reservas, tus pagos y tu expediente.',
  _ =>
    'El negocio: agenda, ${t.miembros.toLowerCase()}, cobros y reportes, '
        'según tus permisos.',
};

/// Los roles como tarjetas: nombre, qué verá con él y la marca del activo (o del de
/// la última vez, al entrar).
class ListaRoles extends StatelessWidget {
  const ListaRoles({
    super.key,
    required this.sesion,
    required this.etiquetaMarca,
    required this.onElegir,
    this.aplicando,
  });

  final Sesion sesion;
  final String etiquetaMarca;
  final ValueChanged<String> onElegir;
  final String? aplicando;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (final rol in sesion.rolesDisponibles)
          Card(
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
              side: BorderSide(
                color: rol.clave == sesion.rol
                    ? TemaAgendaUno.acento
                    : Colors.transparent,
              ),
            ),
            child: ListTile(
              key: Key('rol-${rol.clave}'),
              title: Text(
                nombreDeRol(rol, sesion.terminologia),
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              subtitle: Text(detalleDeFaceta(rol.faceta, sesion.terminologia)),
              trailing: aplicando == rol.clave
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : rol.clave == sesion.rol
                  ? Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 7,
                          height: 7,
                          decoration: const BoxDecoration(
                            color: TemaAgendaUno.acento,
                            shape: BoxShape.circle,
                          ),
                        ),
                        const SizedBox(width: 6),
                        Text(etiquetaMarca),
                      ],
                    )
                  : null,
              onTap: aplicando == null ? () => onElegir(rol.clave) : null,
            ),
          ),
      ],
    );
  }
}

/// «¿Cómo quieres entrar?»: al iniciar sesión, quien tiene más de un rol elige con
/// cuál trabaja (el de la última vez viene marcado). Con uno solo no se pasa aquí.
class ElegirRolScreen extends ConsumerStatefulWidget {
  const ElegirRolScreen({super.key});

  @override
  ConsumerState<ElegirRolScreen> createState() => _ElegirRolScreenState();
}

class _ElegirRolScreenState extends ConsumerState<ElegirRolScreen> {
  String? _aplicando;

  Future<void> _elegir(String clave) async {
    setState(() => _aplicando = clave);
    try {
      await ref.read(sesionProvider.notifier).cambiarRol(clave);
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('No se pudo cambiar de rol.')),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _aplicando = null);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    if (sesion == null) {
      return const SizedBox.shrink();
    }
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 32, 20, 20),
          children: [
            Text(
              '¿Cómo quieres entrar?',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(
              'Tienes más de un rol en ${sesion.estudioNombre ?? 'este negocio'}. '
              'Cada uno muestra sus propias opciones; puedes cambiarlo cuando '
              'quieras desde tu perfil.',
              style: const TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            const SizedBox(height: 20),
            ListaRoles(
              sesion: sesion,
              etiquetaMarca: 'La última vez',
              aplicando: _aplicando,
              onElegir: _elegir,
            ),
            const SizedBox(height: 12),
            TextButton(
              onPressed: _aplicando == null
                  ? () => ref.read(sesionProvider.notifier).cerrar()
                  : null,
              child: const Text('Cerrar sesión'),
            ),
          ],
        ),
      ),
    );
  }
}
