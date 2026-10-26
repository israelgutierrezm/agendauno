import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';

/// «Eliminar mi cuenta» (lo piden Apple y Google, para cualquier rol): se dice antes
/// qué pasa y en qué plazo, y se confirma con la contraseña. Al eliminarla se cierra
/// la sesión en el teléfono y el acceso lo dice.
Future<void> mostrarEliminarCuenta(BuildContext context) =>
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => const EliminarCuentaSheet(),
    );

class EliminarCuentaSheet extends ConsumerStatefulWidget {
  const EliminarCuentaSheet({super.key});

  @override
  ConsumerState<EliminarCuentaSheet> createState() =>
      _EliminarCuentaSheetState();
}

class _EliminarCuentaSheetState extends ConsumerState<EliminarCuentaSheet> {
  final _password = TextEditingController();
  final _motivo = TextEditingController();
  var _eliminando = false;
  String? _error;

  @override
  void dispose() {
    _password.dispose();
    _motivo.dispose();
    super.dispose();
  }

  Future<void> _eliminar() async {
    if (_password.text.isEmpty || _eliminando) {
      setState(() => _error = 'Escribe tu contraseña para confirmar.');
      return;
    }
    final seguir = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        title: const Text('¿Eliminar tu cuenta?'),
        content: const Text('No se puede deshacer.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(contexto).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: contexto.colores.error,
            ),
            onPressed: () => Navigator.of(contexto).pop(true),
            child: const Text('Sí, eliminarla'),
          ),
        ],
      ),
    );
    if (seguir != true || !mounted) {
      return;
    }
    setState(() {
      _eliminando = true;
      _error = null;
    });
    try {
      // Al terminar, la sesión se cierra: la app vuelve al acceso con el aviso.
      await ref
          .read(sesionProvider.notifier)
          .eliminarCuenta(_password.text, motivo: _motivo.text);
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _error = mensajeDeError(
            e,
            porDefecto: 'No se pudo eliminar tu cuenta.',
          );
          _eliminando = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    final negocio = (sesion?.estudioNombre ?? '').trim();
    final deNegocio = negocio.isEmpty ? 'este negocio' : negocio;
    // Quien es cliente (tiene ficha) además pide la baja de sus datos.
    final esCliente =
        (sesion?.esMiembro ?? false) || (sesion?.tieneFicha ?? false);
    final sinContrasena = !(sesion?.tieneContrasena ?? true);
    final esDueno = sesion?.roles.contains('propietario') ?? false;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          0,
          20,
          16 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Eliminar mi cuenta',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              Text('Qué pasa con tu cuenta en $deNegocio:'),
              const SizedBox(height: 4),
              const _Punto(
                'Se elimina en el acto: se cierra tu sesión en todos tus '
                'dispositivos y ya no puedes entrar con ella, ni en la app ni '
                'en la web.',
              ),
              if (esCliente)
                const _Punto(
                  'Tus datos se borran en el plazo que marca la ley. El negocio '
                  'solo conserva lo que está obligado a guardar, como tus pagos y '
                  'facturas.',
                ),
              _Punto(
                'No se puede deshacer: para volver, $deNegocio tendría que '
                'invitarte de nuevo.',
              ),
              _Punto(
                'Es solo de $deNegocio: tus cuentas en otros negocios siguen '
                'igual.',
              ),
              if (esDueno)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'Si eres el único dueño, primero da de baja el negocio '
                    'desde Mi suscripción en la web.',
                    key: const Key('aviso-dueno'),
                    style: TextStyle(color: context.colores.aviso),
                  ),
                ),
              const SizedBox(height: 12),
              if (sinContrasena)
                Text(
                  'Entras con Google y aún no tienes contraseña. Créala en '
                  '«Contraseña», aquí en Mi perfil, o con «¿Olvidaste tu '
                  'contraseña?» al entrar, y vuelve aquí para confirmar.',
                  key: const Key('sin-contrasena'),
                  style: TextStyle(color: context.colores.aviso),
                )
              else ...[
                TextField(
                  key: const Key('contrasena-eliminar'),
                  controller: _password,
                  enabled: !_eliminando,
                  obscureText: true,
                  autofillHints: const [AutofillHints.password],
                  decoration: const InputDecoration(
                    labelText: 'Tu contraseña, para confirmar',
                  ),
                ),
                const SizedBox(height: 8),
                TextField(
                  key: const Key('motivo-eliminar'),
                  controller: _motivo,
                  enabled: !_eliminando,
                  maxLength: 500,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: '¿Por qué te vas? (opcional)',
                  ),
                ),
              ],
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    _error!,
                    key: const Key('error-eliminar'),
                    style: TextStyle(color: context.colores.error),
                  ),
                ),
              const SizedBox(height: 12),
              if (!sinContrasena)
                FilledButton(
                  key: const Key('confirmar-eliminar'),
                  style: FilledButton.styleFrom(
                    backgroundColor: context.colores.error,
                  ),
                  onPressed: _eliminando ? null : _eliminar,
                  child: Text(
                    _eliminando ? 'Eliminando…' : 'Eliminar mi cuenta',
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Punto extends StatelessWidget {
  const _Punto(this.texto);

  final String texto;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 4),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('•  '),
        Expanded(child: Text(texto)),
      ],
    ),
  );
}
