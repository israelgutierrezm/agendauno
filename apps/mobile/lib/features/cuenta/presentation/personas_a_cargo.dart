import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/mensaje_error.dart';
import '../../../core/network/participante_en_uso.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../data/participantes.dart';

/// De quién es la cuenta que se ve (familias, ADR 0123): la suya o la de una
/// persona a su cargo con la autorización vigente. Solo aparece si tiene a alguien
/// autorizado; al elegir, reservas, planes, pagos y documentos son de esa persona y
/// lo dice siempre a la vista.
class SelectorParticipante extends ConsumerWidget {
  const SelectorParticipante({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final autorizadas =
        ref.watch(personasACargoProvider).value?.autorizadas ?? const [];
    if (autorizadas.isEmpty) {
      return const SizedBox.shrink();
    }
    final actual = ref.watch(participanteEnUsoProvider);
    final nombre = autorizadas
        .where((p) => p.personaId == actual)
        .firstOrNull
        ?.nombre;

    return Material(
      color: Theme.of(context).colorScheme.surfaceContainerLow,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 6),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Text(
                  'Cuenta de',
                  style: TextStyle(fontWeight: FontWeight.w500),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: DropdownButton<String?>(
                    key: const ValueKey('selector-participante'),
                    isExpanded: true,
                    value: nombre == null ? null : actual,
                    items: [
                      const DropdownMenuItem<String?>(
                        value: null,
                        child: Text('Mi cuenta'),
                      ),
                      for (final p in autorizadas)
                        DropdownMenuItem<String?>(
                          value: p.personaId,
                          child: Text(p.nombre),
                        ),
                    ],
                    onChanged: (id) =>
                        ref.read(participanteEnUsoProvider.notifier).elegir(id),
                  ),
                ),
              ],
            ),
            if (nombre != null)
              Text(
                'Estás viendo la cuenta de $nombre: sus '
                '${(ref.watch(sesionProvider)?.esCitas ?? false) ? 'citas' : 'reservas'}, '
                'planes, pagos y documentos.',
                style: TextStyle(
                  fontSize: 13,
                  color: context.colores.textoSuave,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Las personas a cargo en el Inicio de la cuenta propia: la autorización que
/// falta (se lee el texto vigente del negocio y se acepta), pasar a su cuenta y
/// retirar la autorización.
class PersonasACargoCard extends ConsumerWidget {
  const PersonasACargoCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final datos = ref.watch(personasACargoProvider).value;
    if (datos == null ||
        datos.lista.isEmpty ||
        ref.watch(participanteEnUsoProvider) != null) {
      return const SizedBox.shrink();
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Personas a tu cargo',
              style: TextStyle(fontWeight: FontWeight.w500, fontSize: 16),
            ),
            for (final p in datos.lista)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            p.nombre,
                            style: const TextStyle(fontWeight: FontWeight.w500),
                          ),
                          Row(
                            children: [
                              Container(
                                width: 6,
                                height: 6,
                                decoration: BoxDecoration(
                                  shape: BoxShape.circle,
                                  color: p.autorizada
                                      ? context.colores.exito
                                      : context.colores.aviso,
                                ),
                              ),
                              const SizedBox(width: 6),
                              Text(
                                p.autorizada
                                    ? 'Autorizada'
                                    : 'Falta la autorización',
                                style: TextStyle(
                                  fontSize: 12,
                                  color: context.colores.textoSuave,
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    if (!p.autorizada)
                      FilledButton(
                        onPressed: () => _autorizar(context, ref, p, datos),
                        child: const Text('Autorizar'),
                      )
                    else ...[
                      TextButton(
                        onPressed: () => ref
                            .read(participanteEnUsoProvider.notifier)
                            .elegir(p.personaId),
                        child: const Text('Ver su cuenta'),
                      ),
                      TextButton(
                        onPressed: () => _retirar(context, ref, p),
                        style: TextButton.styleFrom(
                          foregroundColor: Theme.of(context).colorScheme.error,
                        ),
                        child: const Text('Retirar'),
                      ),
                    ],
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _autorizar(
    BuildContext context,
    WidgetRef ref,
    Participante p,
    PersonasACargo datos,
  ) async {
    final acepto = await showDialog<bool>(
      context: context,
      builder: (_) => _DialogoAutorizacion(nombre: p.nombre, datos: datos),
    );
    if (acepto != true || !context.mounted) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    try {
      await ref
          .read(participantesRepositoryProvider)
          ?.autorizar(p.tutelaId, datos.version);
      messenger.showSnackBar(
        SnackBar(
          content: Text('Listo: ya puedes gestionar la cuenta de ${p.nombre}.'),
        ),
      );
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
    }
    ref.invalidate(personasACargoProvider);
  }

  Future<void> _retirar(
    BuildContext context,
    WidgetRef ref,
    Participante p,
  ) async {
    final si = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        title: const Text('Retirar autorización'),
        content: Text(
          '¿Retirar tu autorización para ${p.nombre}? Ya no podrás ver ni '
          '${(ref.read(sesionProvider)?.esCitas ?? false) ? 'agendar' : 'reservar'} '
          'por esa persona; lo que ya está hecho se conserva.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(contexto, false),
            child: const Text('Cancelar'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(contexto, true),
            child: const Text('Retirar'),
          ),
        ],
      ),
    );
    if (si != true || !context.mounted) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    try {
      await ref.read(participantesRepositoryProvider)?.retirar(p.tutelaId);
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
    }
    ref.invalidate(personasACargoProvider);
  }
}

/// El texto vigente de la autorización: se lee y se acepta con una casilla.
class _DialogoAutorizacion extends StatefulWidget {
  const _DialogoAutorizacion({required this.nombre, required this.datos});

  final String nombre;
  final PersonasACargo datos;

  @override
  State<_DialogoAutorizacion> createState() => _DialogoAutorizacionState();
}

class _DialogoAutorizacionState extends State<_DialogoAutorizacion> {
  bool _acepto = false;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Autorización del responsable'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Para gestionar la cuenta de ${widget.nombre}, lee y acepta la autorización del negocio:',
            ),
            const SizedBox(height: 12),
            Text(widget.datos.texto, key: const ValueKey('texto-autorizacion')),
            const SizedBox(height: 8),
            Text(
              'Versión ${widget.datos.version} del texto del negocio.',
              style: TextStyle(fontSize: 12, color: context.colores.textoSuave),
            ),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              controlAffinity: ListTileControlAffinity.leading,
              value: _acepto,
              onChanged: (v) => setState(() => _acepto = v ?? false),
              title: const Text('Leí el texto y lo acepto.'),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: const Text('Cancelar'),
        ),
        FilledButton(
          onPressed: _acepto ? () => Navigator.pop(context, true) : null,
          child: const Text('Autorizar'),
        ),
      ],
    );
  }
}
