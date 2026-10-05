import 'package:flutter/material.dart';

import 'theme/tema_agendauno.dart';

/// Personal sin sucursal asignada en un negocio con varias (ADR 0098): no ve agenda,
/// clientes ni cobros hasta que el administrador le asigne una. Se lo decimos claro.
class AvisoSinSucursal extends StatelessWidget {
  const AvisoSinSucursal({super.key});

  @override
  Widget build(BuildContext context) => const Card(
    key: Key('sin-sucursal'),
    child: Padding(
      padding: EdgeInsets.all(16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: EdgeInsets.only(top: 6),
            child: CircleAvatar(
              radius: 4,
              backgroundColor: TemaAgendaUno.aviso,
            ),
          ),
          SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Aún no tienes una sucursal asignada',
                  style: TextStyle(fontWeight: FontWeight.w600),
                ),
                SizedBox(height: 4),
                Text(
                  'Mientras tanto no verás agenda, clientes ni cobros. Pide al '
                  'administrador del negocio que te asigne una.',
                  style: TextStyle(color: TemaAgendaUno.textoSuave),
                ),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}
