import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../../agenda/data/agenda_models.dart';
import '../../agenda/data/agenda_repository.dart';

/// Las sedes de quien cobra (GET /sucursales: solo las suyas).
final sedesDeCobroProvider = FutureProvider.autoDispose<List<SucursalAgenda>>((
  ref,
) async {
  final repo = ref.watch(agendaRepositoryProvider);
  return repo == null ? const [] : repo.sucursales();
});

/// La sede donde se cobra: su caja registra el cobro (ADR 0175), como la sede de la
/// barra en la web. Con una sola, esa; con varias, se elige (no se adivina).
/// [alElegir] recibe la sede, o null mientras no hay una.
class SedeDeCobro extends ConsumerStatefulWidget {
  const SedeDeCobro({
    super.key,
    required this.alElegir,
    this.habilitado = true,
  });

  final ValueChanged<String?> alElegir;
  final bool habilitado;

  @override
  ConsumerState<SedeDeCobro> createState() => _SedeDeCobroState();
}

class _SedeDeCobroState extends ConsumerState<SedeDeCobro> {
  String? _sede;

  @override
  Widget build(BuildContext context) {
    ref.listen(sedesDeCobroProvider, (_, sedes) {
      final lista = sedes.value;
      if (lista != null && lista.length == 1 && _sede != lista.single.id) {
        _sede = lista.single.id;
        widget.alElegir(_sede);
      }
    });
    return ref
        .watch(sedesDeCobroProvider)
        .when(
          loading: () => const LinearProgressIndicator(),
          error: (_, _) => Row(
            children: [
              Expanded(
                child: Text(
                  'No se pudieron cargar las sedes.',
                  style: TextStyle(color: context.colores.error),
                ),
              ),
              TextButton(
                onPressed: () => ref.invalidate(sedesDeCobroProvider),
                child: const Text('Reintentar'),
              ),
            ],
          ),
          data: (sedes) {
            if (sedes.length == 1 && _sede == null) {
              // La primera vez que llegan (sin pasar por el listener).
              _sede = sedes.single.id;
              WidgetsBinding.instance.addPostFrameCallback((_) {
                if (mounted) {
                  widget.alElegir(_sede);
                }
              });
            }
            if (sedes.length <= 1) {
              return sedes.isEmpty
                  ? Text(
                      'No tienes una sede asignada para cobrar.',
                      style: TextStyle(color: context.colores.error),
                    )
                  : Text(
                      'Entra en la caja de ${sedes.single.nombre}.',
                      key: const Key('sede-unica'),
                      style: TextStyle(color: context.colores.textoSuave),
                    );
            }
            return DropdownButtonFormField<String>(
              key: const Key('sede-de-cobro'),
              isExpanded: true,
              initialValue: _sede,
              decoration: const InputDecoration(
                labelText: 'Sede donde se cobra',
                helperText: 'El cobro entra en la caja de esa sede.',
              ),
              items: [
                for (final s in sedes)
                  DropdownMenuItem(value: s.id, child: Text(s.nombre)),
              ],
              onChanged: widget.habilitado
                  ? (v) {
                      setState(() => _sede = v);
                      widget.alElegir(v);
                    }
                  : null,
            );
          },
        );
  }
}
