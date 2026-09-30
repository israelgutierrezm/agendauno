import 'package:flutter/material.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/resumen_mes.dart';

/// «Este mes» en el Inicio de quien ve los números del negocio (ADR 0081): ingresos,
/// ocupación, inasistencias y clientes activos, y qué tan llena va la agenda de
/// cada quien. El detalle está en Reportes, en la web.
class ResumenMesCard extends StatelessWidget {
  const ResumenMesCard({
    super.key,
    required this.mes,
    required this.clientes,
    required this.hoy,
  });

  final ResumenMes mes;

  /// Cómo llama el negocio a su clientela (alumnos, clientes, pacientes…).
  final String clientes;
  final DateTime hoy;

  static const _meses = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
  ];

  String _pct(int? valor) => valor == null ? '—' : '$valor%';

  @override
  Widget build(BuildContext context) {
    final equipo = mes.equipo.take(5).toList();
    Widget dato(String etiqueta, String valor) => SizedBox(
      width: 140,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            etiqueta,
            style: const TextStyle(
              fontSize: 12,
              color: TemaAgendaUno.textoSuave,
            ),
          ),
          Text(
            valor,
            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: TemaAgendaUno.superficie,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: TemaAgendaUno.borde),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Este mes',
            style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
          ),
          Text(
            'Del 1 al ${hoy.day} de ${_meses[hoy.month - 1]}',
            style: const TextStyle(color: TemaAgendaUno.textoSuave),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 12,
            runSpacing: 10,
            children: [
              dato('Ingresos', Formato.dinero(mes.ingresosMinor)),
              dato('Ocupación', _pct(mes.ocupacionPct)),
              dato('Inasistencias', _pct(mes.inasistenciaPct)),
              dato(
                '${clientes[0].toUpperCase()}${clientes.substring(1)} activos',
                '${mes.clientesActivos}',
              ),
            ],
          ),
          if (equipo.isNotEmpty) ...[
            const SizedBox(height: 16),
            const Text(
              'Agenda del equipo',
              style: TextStyle(fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 6),
            for (final p in equipo) _FilaProfesional(p: p),
          ],
        ],
      ),
    );
  }
}

class _FilaProfesional extends StatelessWidget {
  const _FilaProfesional({required this.p});

  final OcupacionProfesional p;

  String _horas(int minutos) {
    final horas = minutos / 60;
    return horas == horas.roundToDouble()
        ? '${horas.round()} h'
        : '${horas.toStringAsFixed(1)} h';
  }

  @override
  Widget build(BuildContext context) {
    final pct = p.ocupacionPct;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  p.nombre ?? 'Sin asignar',
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              Text(
                pct == null
                    ? 'Sin horario'
                    : '$pct% · ${_horas(p.agendadoMin)} de ${_horas(p.disponibleMin)}',
                style: TextStyle(
                  fontSize: 13,
                  color: pct == null ? TemaAgendaUno.textoSuave : null,
                ),
              ),
            ],
          ),
          if (pct != null) ...[
            const SizedBox(height: 4),
            ClipRRect(
              borderRadius: BorderRadius.circular(99),
              child: LinearProgressIndicator(
                value: pct / 100,
                minHeight: 4,
                backgroundColor: TemaAgendaUno.borde,
                color: TemaAgendaUno.acento,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
