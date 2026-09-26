import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';

/// Movimientos de créditos de un plan: por qué cambió su saldo ("Asistencia",
/// "Cancelación tardía", "Créditos vencidos"…), de qué clase y con qué saldo quedó.
class MovimientosSheet extends ConsumerWidget {
  const MovimientosSheet({required this.derechoId, super.key});

  final String derechoId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final repo = ref.watch(cuentaRepositoryProvider);
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: FutureBuilder<List<MovimientoCredito>>(
          future: repo?.movimientos(derechoId),
          builder: (context, snap) {
            if (snap.hasError) {
              return const Text('No se pudieron cargar los movimientos.');
            }
            if (!snap.hasData) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final lista = snap.data!;
            return Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Movimientos',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 8),
                if (lista.isEmpty)
                  const Text(
                    'Aún no hay movimientos.',
                    style: TextStyle(color: TemaAgendaUno.textoSuave),
                  )
                else
                  Flexible(
                    child: ListView.separated(
                      shrinkWrap: true,
                      itemCount: lista.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (_, i) => _Movimiento(lista[i]),
                    ),
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _Movimiento extends StatelessWidget {
  const _Movimiento(this.m);

  final MovimientoCredito m;

  @override
  Widget build(BuildContext context) {
    final detalle = m.clase != null
        ? '${m.clase} · ${Formato.fechaHora(m.claseIniciaEn)}'
        : Formato.fechaHora(m.fecha);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  m.concepto,
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                Text(
                  detalle,
                  style: const TextStyle(color: TemaAgendaUno.textoSuave),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                MovimientoCredito.creditos(m.unidades),
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              Text(
                'Saldo ${MovimientoCredito.creditos(m.saldoPosterior, conSigno: false)}',
                style: const TextStyle(
                  color: TemaAgendaUno.textoSuave,
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
