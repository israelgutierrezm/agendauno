import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../application/cuenta_controller.dart';
import '../data/corte_planes.dart';
import 'cuenta_widgets.dart';
import 'movimientos_sheet.dart';

/// "Mis planes": el corte de cada paquete o membresía (ADR 0050). Los vigentes
/// arriba; los anteriores, después y atenuados.
class CortePlanesSeccion extends ConsumerWidget {
  const CortePlanesSeccion({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final estado = ref.watch(cortePlanesProvider);
    return estado.when(
      loading: () => const Padding(
        padding: EdgeInsets.all(16),
        child: Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => const TextoVacio('No se pudieron cargar tus planes.'),
      data: (planes) {
        if (planes.isEmpty) {
          return const TextoVacio('Aún no tienes planes.');
        }
        final actuales = planes.where((p) => p.actual).toList();
        final anteriores = planes.where((p) => !p.actual).toList();
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final p in actuales) _PlanCard(p),
            if (anteriores.isNotEmpty) ...[
              const Padding(
                padding: EdgeInsets.fromLTRB(4, 12, 4, 4),
                child: Text(
                  'Planes anteriores',
                  style: TextStyle(
                    color: TemaAgendaUno.textoSuave,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
              for (final p in anteriores)
                Opacity(opacity: 0.75, child: _PlanCard(p)),
            ],
          ],
        );
      },
    );
  }
}

class _PlanCard extends StatefulWidget {
  const _PlanCard(this.p);

  final PlanCorte p;

  @override
  State<_PlanCard> createState() => _PlanCardState();
}

class _PlanCardState extends State<_PlanCard> {
  bool _verUsos = false;

  static String _fecha(String? iso) =>
      iso == null ? '—' : Formato.dia(DateTime.parse(iso));

  Color get _colorEstado => switch (widget.p.estado) {
    'vigente' => TemaAgendaUno.exito,
    'pausado' || 'suspendido' => TemaAgendaUno.aviso,
    _ => TemaAgendaUno.textoSuave,
  };

  @override
  Widget build(BuildContext context) {
    final p = widget.p;
    final vigencia = p.hasta != null
        ? 'Del ${_fecha(p.desde)} al ${_fecha(p.hasta)}'
        : 'Desde el ${_fecha(p.desde)}, no vence';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    p.producto ?? '—',
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                Container(
                  width: 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: _colorEstado,
                    shape: BoxShape.circle,
                  ),
                ),
                const SizedBox(width: 6),
                Text(p.estadoTexto),
              ],
            ),
            const SizedBox(height: 2),
            Text(
              [
                vigencia,
                // Si no vale en todas las sucursales, en cuáles.
                ?p.cobertura.texto,
                if (p.aplicaA.isNotEmpty) 'Sirve para: ${p.aplicaA.join(', ')}',
              ].join(' · '),
              style: const TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            const SizedBox(height: 12),
            // Los números del plan.
            Wrap(
              spacing: 20,
              runSpacing: 8,
              children: [
                if (p.ilimitado) _numero('Ilimitado', '∞', false),
                for (final (etiqueta, valor) in p.numeros)
                  _numero(etiqueta, valor, etiqueta == 'Disponibles'),
              ],
            ),
            if (p.extras.isNotEmpty) ...[
              const SizedBox(height: 12),
              const Text(
                'Clases extra',
                style: TextStyle(fontWeight: FontWeight.w600),
              ),
              for (final x in p.extras)
                Text(
                  '${x.producto ?? '—'} · ${_fecha(x.comprado)} · ${PlanCorte.clases(x.usadas)} de ${PlanCorte.clases(x.unidades)} usadas',
                  style: const TextStyle(color: TemaAgendaUno.textoSuave),
                ),
            ],
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              children: [
                if (p.usos.isNotEmpty)
                  TextButton(
                    style: TextButton.styleFrom(padding: EdgeInsets.zero),
                    onPressed: () => setState(() => _verUsos = !_verUsos),
                    child: Text('Cómo lo usaste (${p.usos.length})'),
                  )
                else
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 8),
                    child: Text(
                      'Aún no se ha usado.',
                      style: TextStyle(color: TemaAgendaUno.textoSuave),
                    ),
                  ),
                if (!p.ilimitado && p.derechoId.isNotEmpty)
                  TextButton(
                    style: TextButton.styleFrom(padding: EdgeInsets.zero),
                    onPressed: () => showModalBottomSheet<void>(
                      context: context,
                      isScrollControlled: true,
                      showDragHandle: true,
                      builder: (_) => MovimientosSheet(derechoId: p.derechoId),
                    ),
                    child: const Text('Ver movimientos'),
                  ),
              ],
            ),
            if (_verUsos)
              for (final u in p.usos)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text.rich(
                          TextSpan(
                            children: [
                              TextSpan(
                                text: Formato.fechaHora(
                                  u.iniciaEn.toIso8601String(),
                                ),
                              ),
                              TextSpan(
                                text:
                                    ' · ${u.clase ?? '—'}${u.extra ? ' (extra)' : ''}',
                                style: const TextStyle(
                                  color: TemaAgendaUno.textoSuave,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      Text(
                        u.estadoTexto,
                        style: TextStyle(
                          fontSize: 12,
                          color: u.pideAtencion
                              ? TemaAgendaUno.aviso
                              : TemaAgendaUno.textoSuave,
                        ),
                      ),
                    ],
                  ),
                ),
          ],
        ),
      ),
    );
  }

  Widget _numero(String etiqueta, String valor, bool destacado) => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(
        etiqueta,
        style: const TextStyle(fontSize: 12, color: TemaAgendaUno.textoSuave),
      ),
      Text(
        valor,
        style: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: destacado ? TemaAgendaUno.acento : TemaAgendaUno.texto,
        ),
      ),
    ],
  );
}
