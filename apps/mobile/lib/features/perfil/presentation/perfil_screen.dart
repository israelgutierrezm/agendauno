import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/presentation/cuenta_screen.dart' show hacerConAviso;

/// Mi perfil: foto, nombre y contraseña de quien tiene la sesión, y cerrar sesión.
/// La foto se cambia desde la web.
class PerfilScreen extends ConsumerStatefulWidget {
  const PerfilScreen({super.key});

  @override
  ConsumerState<PerfilScreen> createState() => _PerfilScreenState();
}

class _PerfilScreenState extends ConsumerState<PerfilScreen> {
  final _nombre = TextEditingController();
  final _primerApellido = TextEditingController();
  final _segundoApellido = TextEditingController();
  final _actual = TextEditingController();
  final _nueva = TextEditingController();
  final _confirmacion = TextEditingController();
  bool _guardando = false;

  @override
  void initState() {
    super.initState();
    final sesion = ref.read(sesionProvider);
    _nombre.text = sesion?.nombrePila ?? sesion?.nombre ?? '';
    _primerApellido.text = sesion?.primerApellido ?? '';
    _segundoApellido.text = sesion?.segundoApellido ?? '';
  }

  @override
  void dispose() {
    for (final c in [
      _nombre,
      _primerApellido,
      _segundoApellido,
      _actual,
      _nueva,
      _confirmacion,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  String? _opcional(TextEditingController c) =>
      c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _guardarNombre() async {
    setState(() => _guardando = true);
    await hacerConAviso(
      context,
      () => ref
          .read(sesionProvider.notifier)
          .guardarPerfil(
            nombre: _nombre.text.trim(),
            primerApellido: _opcional(_primerApellido),
            segundoApellido: _opcional(_segundoApellido),
          ),
      exito: 'Perfil actualizado.',
    );
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  Future<void> _cambiarContrasena() async {
    if (_nueva.text != _confirmacion.text) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Las contraseñas no coinciden.')),
      );
      return;
    }
    setState(() => _guardando = true);
    await hacerConAviso(context, () async {
      await ref
          .read(sesionProvider.notifier)
          .cambiarContrasena(
            actualContrasena: _opcional(_actual),
            nueva: _nueva.text,
            confirmacion: _confirmacion.text,
          );
      _actual.clear();
      _nueva.clear();
      _confirmacion.clear();
    }, exito: 'Contraseña actualizada.');
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    if (sesion == null) {
      return const Scaffold();
    }
    final iniciales = sesion.nombre
        .split(' ')
        .where((p) => p.isNotEmpty)
        .take(2)
        .map((p) => p[0].toUpperCase())
        .join();

    return Scaffold(
      appBar: AppBar(title: const Text('Mi perfil')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          Row(
            children: [
              CircleAvatar(
                radius: 32,
                backgroundColor: TemaAgendaUno.acentoSuave,
                foregroundImage: sesion.fotoUrl != null
                    ? NetworkImage(sesion.fotoUrl!)
                    : null,
                child: Text(
                  iniciales,
                  style: const TextStyle(
                    color: TemaAgendaUno.acento,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      sesion.nombre,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    if (sesion.email != null)
                      Text(
                        sesion.email!,
                        style: const TextStyle(color: TemaAgendaUno.textoSuave),
                      ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 24),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Nombre',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _nombre,
                    decoration: const InputDecoration(labelText: 'Nombre(s)'),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _primerApellido,
                    decoration: const InputDecoration(
                      labelText: 'Primer apellido',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _segundoApellido,
                    decoration: const InputDecoration(
                      labelText: 'Segundo apellido (opcional)',
                    ),
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _guardando ? null : _guardarNombre,
                    child: const Text('Guardar'),
                  ),
                ],
              ),
            ),
          ),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Contraseña',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 12),
                  if (sesion.tieneContrasena) ...[
                    TextField(
                      controller: _actual,
                      obscureText: true,
                      decoration: const InputDecoration(
                        labelText: 'Contraseña actual',
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  TextField(
                    controller: _nueva,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Nueva contraseña (mínimo 8)',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _confirmacion,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Confirma la nueva contraseña',
                    ),
                  ),
                  const SizedBox(height: 16),
                  OutlinedButton(
                    onPressed: _guardando ? null : _cambiarContrasena,
                    child: const Text('Cambiar contraseña'),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 8),
          TextButton.icon(
            style: TextButton.styleFrom(foregroundColor: TemaAgendaUno.error),
            icon: const Icon(Icons.logout),
            label: const Text('Cerrar sesión'),
            onPressed: () async {
              final navegador = Navigator.of(context);
              await ref.read(sesionProvider.notifier).cerrar();
              navegador.popUntil((r) => r.isFirst);
            },
          ),
        ],
      ),
    );
  }
}
