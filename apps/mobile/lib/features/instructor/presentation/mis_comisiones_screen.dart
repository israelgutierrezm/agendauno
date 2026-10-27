import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../cuenta/presentation/cuenta_widgets.dart';
import '../application/mis_clases_controller.dart';
import '../application/mis_comisiones_controller.dart';
import '../data/mis_comisiones.dart';

/// «Mis comisiones» (ADR 0219), como en la web: lo que lleva ganado quien atiende
/// en el periodo con su esquema y sus propinas, y lo de sus citas que aún no
/// atiende. Solo lo suyo; el negocio se lo paga en su corte de nómina.
class MisComisionesScreen extends ConsumerWidget {
  const MisComisionesScreen({super.key});

  Future<void> _elegirPeriodo(BuildContext context, WidgetRef ref) async {
    final actual = ref.read(periodoComisionesProvider);
    final hoy = hoyDelNegocio(ref.read(sesionProvider), DateTime.now());
    final rango = await showDateRangePicker(
      context: context,
      firstDate: DateTime(hoy.year - 3),
      // También hacia adelante: lo de sus citas agendadas (por venir).
      lastDate: DateTime(hoy.year + 1, hoy.month, hoy.day),
      currentDate: hoy,
      initialDateRange: DateTimeRange(start: actual.desde, end: actual.hasta),
      helpText: 'Periodo',
      saveText: 'Listo',
    );
    if (rango != null) {
      ref
          .read(periodoComisionesProvider.notifier)
          .fijar(rango.start, rango.end);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Solo en negocios de citas: sin sesión, sus palabras.
    final terminos =
        ref.watch(sesionProvider)?.terminologia ?? Terminologia.citas;
    final citas = terminos.sesiones.toLowerCase();
    final periodo = ref.watch(periodoComisionesProvider);
    final estado = ref.watch(misComisionesProvider);
    final comisiones = estado.value;

    return Scaffold(
      appBar: AppBar(title: const Text('Mis comisiones')),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(misComisionesProvider.future),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          children: [
            Text(
              'Lo que llevas ganado con tus $citas y tus propinas. El negocio '
              'te lo paga en su corte de nómina.',
              style: TextStyle(color: context.colores.textoSuave),
            ),
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                key: const Key('periodo-comisiones'),
                icon: const Icon(Icons.date_range_outlined, size: 18),
                label: Text(textoPeriodo(periodo.desde, periodo.hasta)),
                onPressed: () => _elegirPeriodo(context, ref),
              ),
            ),
            const SizedBox(height: 16),
            // Carga, error y vacío, siempre distintos.
            if (estado.hasError && !estado.isLoading)
              ErrorDeCarga(
                mensaje: mensajeDeError(
                  estado.error!,
                  porDefecto: 'No se pudieron cargar tus comisiones.',
                ),
                onReintentar: () => ref.invalidate(misComisionesProvider),
              )
            else if (comisiones == null && estado.isLoading)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Center(child: CircularProgressIndicator()),
              )
            else ...[
              // Otro periodo: lo anterior queda a la vista mientras llega.
              if (estado.isLoading) const LinearProgressIndicator(minHeight: 2),
              if (comisiones == null || comisiones.vacio)
                const TextoVacio('Aún no tienes comisiones en este periodo.')
              else
                ResumenComisiones(comisiones: comisiones, terminos: terminos),
            ],
          ],
        ),
      ),
    );
  }
}

/// "1 oct – 26 oct" (con el año si no es el de [hoy] o cambia en el periodo).
String textoPeriodo(DateTime desde, DateTime hasta, {DateTime? hoy}) {
  final ahora = hoy ?? DateTime.now();
  final conAnio = desde.year != hasta.year || desde.year != ahora.year;
  String dia(DateTime d) {
    final texto = Formato.diaMes(Formato.iso(d));
    return conAnio ? '$texto ${d.year}' : texto;
  }

  return Formato.iso(desde) == Formato.iso(hasta)
      ? dia(desde)
      : '${dia(desde)} – ${dia(hasta)}';
}

/// Lo ganado (comisión y propinas), las propinas y lo por venir, en una sola
/// tarjeta, con su esquema y cuántas atendió.
class ResumenComisiones extends StatelessWidget {
  const ResumenComisiones({
    super.key,
    required this.comisiones,
    this.terminos = Terminologia.citas,
  });

  final MisComisiones comisiones;

  /// Cómo llama el negocio a sus citas.
  final Terminologia terminos;

  @override
  Widget build(BuildContext context) {
    final c = comisiones;
    final cita = terminos.sesion.toLowerCase();
    final citas = terminos.sesiones.toLowerCase();
    Widget dato(String etiqueta, int minor, Key key) => SizedBox(
      width: 140,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            etiqueta,
            style: TextStyle(fontSize: 12, color: context.colores.textoSuave),
          ),
          Text(
            Formato.dinero(minor, c.moneda),
            key: key,
            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
    final atendidas = c.atendidas;
    final nota = [
      ?c.esquema,
      if (c.cuentaAtendidas)
        atendidas == 1 ? 'Atendiste 1 $cita' : 'Atendiste $atendidas $citas',
    ].join(' · ');

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: context.colores.superficie,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: context.colores.borde),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 12,
            runSpacing: 10,
            children: [
              dato('Ganado', c.ganadoMinor, const Key('comisiones-ganado')),
              dato(
                'Propinas',
                c.propinasMinor,
                const Key('comisiones-propinas'),
              ),
              dato(
                'Por venir',
                c.porVenirMinor,
                const Key('comisiones-por-venir'),
              ),
            ],
          ),
          if (nota.isNotEmpty) ...[
            const SizedBox(height: 12),
            Text(nota, key: const Key('comisiones-esquema')),
          ],
          const SizedBox(height: 4),
          Text(
            'Por venir: lo de tus $citas agendadas que aún no atiendes.',
            style: TextStyle(fontSize: 13, color: context.colores.textoSuave),
          ),
        ],
      ),
    );
  }
}
