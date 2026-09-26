import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../application/sesion_controller.dart';

/// Alta de un alumno nuevo en un negocio (el mismo registro público de la web): al
/// crear la cuenta queda con la sesión iniciada. Si su correo ya era de alguien en
/// el negocio, primero confirma desde el correo que le llega.
class RegistroScreen extends ConsumerStatefulWidget {
  const RegistroScreen({super.key, this.slugInicial = ''});

  final String slugInicial;

  @override
  ConsumerState<RegistroScreen> createState() => _RegistroScreenState();
}

class _RegistroScreenState extends ConsumerState<RegistroScreen> {
  late final _slug = TextEditingController(text: widget.slugInicial);
  final _nombre = TextEditingController();
  final _apellido = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _confirmacion = TextEditingController();
  bool _cargando = false;
  String? _error;

  @override
  void dispose() {
    for (final c in [
      _slug,
      _nombre,
      _apellido,
      _email,
      _password,
      _confirmacion,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _crear() async {
    if (_password.text != _confirmacion.text) {
      setState(() => _error = 'Las contraseñas no coinciden.');
      return;
    }
    setState(() {
      _cargando = true;
      _error = null;
    });
    final navegador = Navigator.of(context);
    try {
      final porConfirmar = await ref
          .read(sesionProvider.notifier)
          .registrarAlumno(
            slug: _slug.text.trim(),
            nombre: _nombre.text.trim(),
            primerApellido: _apellido.text.trim(),
            email: _email.text.trim(),
            password: _password.text,
          );
      if (porConfirmar != null && mounted) {
        await showDialog<void>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Confirma tu correo'),
            content: Text(
              'Te enviamos un correo a $porConfirmar. Ábrelo y confirma que es '
              'tuyo para entrar con tu historial; después inicia sesión con tu '
              'contraseña.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text('Entendido'),
              ),
            ],
          ),
        );
      }
      navegador.popUntil((r) => r.isFirst);
    } on DioException catch (e) {
      final data = e.response?.data;
      setState(() {
        _error = (data is Map && data['message'] is String)
            ? data['message'] as String
            : e.response?.statusCode == 404
            ? 'No encontramos ese negocio o no acepta registros en línea.'
            : 'No se pudo crear tu cuenta.';
      });
    } finally {
      if (mounted) {
        setState(() => _cargando = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Crear cuenta')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            TextField(
              controller: _slug,
              decoration: const InputDecoration(
                labelText: 'Dirección del negocio',
                hintText: 'mi-estudio',
              ),
              autocorrect: false,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _nombre,
              decoration: const InputDecoration(labelText: 'Nombre(s)'),
              textCapitalization: TextCapitalization.words,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _apellido,
              decoration: const InputDecoration(labelText: 'Apellido'),
              textCapitalization: TextCapitalization.words,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _email,
              decoration: const InputDecoration(labelText: 'Correo'),
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _password,
              decoration: const InputDecoration(
                labelText: 'Contraseña (mínimo 8)',
              ),
              obscureText: true,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _confirmacion,
              decoration: const InputDecoration(
                labelText: 'Confirma la contraseña',
              ),
              obscureText: true,
            ),
            const SizedBox(height: 16),
            if (_error != null) ...[
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
              const SizedBox(height: 12),
            ],
            FilledButton(
              onPressed: _cargando ? null : _crear,
              child: _cargando
                  ? const SizedBox(
                      height: 18,
                      width: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Crear cuenta'),
            ),
          ],
        ),
      ),
    );
  }
}
