import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import 'cuenta_widgets.dart';
import 'mis_documentos_screen.dart';

/// Expediente del portal: lo que el negocio le pide firmar y sus documentos.
class ExpedienteTab extends ConsumerWidget {
  const ExpedienteTab({super.key, required this.cuenta});

  final MiCuenta cuenta;

  @override
  Widget build(BuildContext context, WidgetRef ref) => RefreshIndicator(
    onRefresh: () => ref.refresh(cuentaProvider.future),
    child: ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
      children: [
        const TituloSeccion('Por firmar'),
        if (cuenta.consentimientos.isEmpty)
          const TextoVacio('No tienes nada por firmar.')
        else
          for (final c in cuenta.consentimientos) ConsentimientoCard(c),
        const TituloSeccion('Documentos'),
        Card(
          child: ListTile(
            leading: const Icon(Icons.description_outlined),
            title: const Text('Mis documentos'),
            subtitle: const Text('Los que te pide el negocio'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute<void>(
                builder: (_) => const MisDocumentosScreen(),
              ),
            ),
          ),
        ),
      ],
    ),
  );
}
