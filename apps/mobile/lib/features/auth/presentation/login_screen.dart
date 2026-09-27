import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../application/sesion_controller.dart';
import 'registro_screen.dart';

/// Acceso tenant-local: el usuario escribe la direccion de su estudio (slug) y sus
/// credenciales. No hay login global ni selector de tenant tras el login.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _slug = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _cargando = false;
  String? _error;

  @override
  void dispose() {
    _slug.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _entrar() async {
    setState(() {
      _cargando = true;
      _error = null;
    });
    try {
      await ref
          .read(sesionProvider.notifier)
          .iniciar(_slug.text.trim(), _email.text.trim(), _password.text);
    } on DioException catch (e) {
      final data = e.response?.data;
      setState(() {
        _error = (data is Map && data['message'] is String)
            ? data['message'] as String
            : 'No se pudo iniciar sesión.';
      });
    } catch (_) {
      setState(() => _error = 'Ocurrió un error inesperado.');
    } finally {
      if (mounted) {
        setState(() => _cargando = false);
      }
    }
  }

  /// Pide el enlace de recuperación con el negocio y el correo escritos (o los que
  /// se capturen en el diálogo). El enlace llega por correo y se abre en la web.
  Future<void> _recuperar() async {
    final slug = TextEditingController(text: _slug.text.trim());
    final email = TextEditingController(text: _email.text.trim());
    final enviar = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Recupera tu contraseña'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text('Te enviaremos un enlace para elegir una contraseña nueva.'),
            const SizedBox(height: 12),
            TextField(
              controller: slug,
              decoration: const InputDecoration(labelText: 'Dirección del negocio'),
              autocorrect: false,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: email,
              decoration: const InputDecoration(labelText: 'Correo'),
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Enviar enlace')),
        ],
      ),
    );
    final datos = (slug.text.trim(), email.text.trim());
    slug.dispose();
    email.dispose();
    if (enviar != true || datos.$1.isEmpty || datos.$2.isEmpty || !mounted) {
      return;
    }

    final messenger = ScaffoldMessenger.of(context);
    try {
      await ref.read(sesionProvider.notifier).pedirRecuperacion(datos.$1, datos.$2);
      messenger.showSnackBar(
        const SnackBar(
          content: Text('Si ese correo tiene cuenta, te llegará un enlace en unos minutos.'),
        ),
      );
    } on DioException catch (e) {
      final data = e.response?.data;
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            (data is Map && data['message'] is String)
                ? data['message'] as String
                : 'No se pudo enviar el enlace. Revisa la dirección del negocio.',
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 380),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('AgendaUno',
                      style: Theme.of(context).textTheme.headlineMedium,
                      textAlign: TextAlign.center),
                  const SizedBox(height: 4),
                  Text('Entra a tu negocio',
                      style: Theme.of(context).textTheme.bodyMedium,
                      textAlign: TextAlign.center),
                  const SizedBox(height: 24),
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
                    controller: _email,
                    decoration: const InputDecoration(labelText: 'Correo'),
                    keyboardType: TextInputType.emailAddress,
                    autocorrect: false,
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _password,
                    decoration: const InputDecoration(labelText: 'Contraseña'),
                    obscureText: true,
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton(
                      onPressed: _cargando ? null : _recuperar,
                      child: const Text('¿Olvidaste tu contraseña?'),
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (_error != null) ...[
                    Text(_error!,
                        style: TextStyle(color: Theme.of(context).colorScheme.error)),
                    const SizedBox(height: 12),
                  ],
                  FilledButton(
                    onPressed: _cargando ? null : _entrar,
                    child: _cargando
                        ? const SizedBox(
                            height: 18,
                            width: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Entrar'),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: _cargando
                        ? null
                        : () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) =>
                                  RegistroScreen(slugInicial: _slug.text.trim()),
                            ),
                          ),
                    child: const Text('¿Primera vez aquí? Crea tu cuenta'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
