import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/calendario/agregar_calendario.dart';
import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/eventos_cuenta.dart';
import 'cuenta_screen.dart';
import 'reprogramar_sheet.dart';

/// Piezas del portal del alumno o cliente que comparten sus pestañas.

class TituloSeccion extends StatelessWidget {
  const TituloSeccion(this.texto, {super.key});

  final String texto;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 20, bottom: 8),
    child: Text(texto, style: Theme.of(context).textTheme.titleMedium),
  );
}

class TextoVacio extends StatelessWidget {
  const TextoVacio(this.texto, {super.key});

  final String texto;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: Text(texto, style: const TextStyle(color: TemaAgendaUno.textoSuave)),
  );
}

/// Acceso directo del Inicio: ícono, título y su dato (en ámbar si pide atención).
class TarjetaAcceso extends StatelessWidget {
  const TarjetaAcceso({
    super.key,
    required this.icono,
    required this.titulo,
    required this.valor,
    required this.onTap,
    this.atencion = false,
    this.tono,
  });

  final IconData icono;
  final String titulo;
  final String valor;
  final VoidCallback onTap;
  final bool atencion;

  /// Con tono (Inicio del alumno): el ícono en su cuadro de ese color y una forma
  /// suave en la esquina. Sin tono, el ícono gris de siempre.
  final Color? tono;

  @override
  Widget build(BuildContext context) => Card(
    margin: EdgeInsets.zero,
    clipBehavior: Clip.antiAlias,
    child: InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: onTap,
      child: Stack(
        fit: StackFit.expand,
        children: [
          if (tono != null)
            Positioned(
              top: -34,
              right: -34,
              child: Container(
                width: 92,
                height: 92,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: tono!.withValues(alpha: 0.09),
                ),
              ),
            ),
          Padding(padding: const EdgeInsets.all(14), child: _contenido()),
        ],
      ),
    ),
  );

  Widget _contenido() => Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      if (tono != null)
        Container(
          width: 40,
          height: 40,
          decoration: BoxDecoration(
            color: tono!.withValues(alpha: 0.14),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(icono, color: tono, size: 22),
        )
      else
        Icon(icono, color: TemaAgendaUno.textoSuave),
      const Spacer(),
      Text(
        titulo,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: const TextStyle(fontWeight: FontWeight.w700),
      ),
      const SizedBox(height: 2),
      Text(
        valor,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          fontSize: 13,
          color: atencion ? TemaAgendaUno.aviso : TemaAgendaUno.textoSuave,
        ),
      ),
    ],
  );
}

/// Rejilla de accesos directos (2 columnas en el teléfono, 3 en tableta).
class RejillaAccesos extends StatelessWidget {
  const RejillaAccesos(this.tarjetas, {super.key});

  final List<TarjetaAcceso> tarjetas;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, c) => GridView.count(
      crossAxisCount: c.maxWidth > 600 ? 3 : 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 10,
      crossAxisSpacing: 10,
      childAspectRatio: 1.35,
      children: tarjetas,
    ),
  );
}

/// Aviso de algo que pide su atención (firmar, aceptar un lugar, pagar).
class AvisoAtencion extends StatelessWidget {
  const AvisoAtencion(this.texto, {super.key, required this.onTap});

  final String texto;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 8),
    child: Material(
      color: TemaAgendaUno.aviso.withValues(alpha: 0.08),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: TemaAgendaUno.aviso.withValues(alpha: 0.35),
            ),
          ),
          child: Text(texto),
        ),
      ),
    ),
  );
}

class ConsentimientoCard extends ConsumerWidget {
  const ConsentimientoCard(this.c, {super.key});

  final ConsentimientoPendiente c;

  @override
  Widget build(BuildContext context, WidgetRef ref) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(c.titulo, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Text(
            c.contenido,
            style: const TextStyle(color: TemaAgendaUno.textoSuave),
          ),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: () => hacerConAviso(
              context,
              () => ref.read(cuentaProvider.notifier).firmar(c.id),
              exito: 'Gracias, quedó firmado.',
            ),
            child: const Text('Acepto'),
          ),
        ],
      ),
    ),
  );
}

/// Una orden pendiente: se paga aquí si el negocio cobra en línea; si no, en
/// recepción.
class PorPagarTile extends ConsumerWidget {
  const PorPagarTile(this.o, {super.key, this.pagoEnLinea = false});

  final OrdenPorPagar o;
  final bool pagoEnLinea;

  @override
  Widget build(BuildContext context, WidgetRef ref) => Card(
    child: ListTile(
      title: Text(o.concepto),
      subtitle: Text(
        [
          if (o.detalle != null && o.detalle!.isNotEmpty) o.detalle!,
          pagoEnLinea
              ? Formato.dinero(o.totalMinor, o.moneda)
              : '${Formato.dinero(o.totalMinor, o.moneda)} · Págalo en recepción',
        ].join('\n'),
      ),
      trailing: pagoEnLinea
          ? FilledButton(
              onPressed: () => pagarEnLinea(context, ref, o.id),
              child: const Text('Pagar'),
            )
          : null,
    ),
  );
}

/// Detalle de una reserva suya: agregar a mi calendario, pagar, aceptar el lugar,
/// cambiar horario o cancelar.
Future<void> mostrarDetalleReserva(
  BuildContext context,
  ReservaMiembro r, {
  bool pagoEnLinea = false,
}) => showModalBottomSheet<void>(
  context: context,
  isScrollControlled: true,
  showDragHandle: true,
  builder: (_) => _DetalleReserva(r, pagoEnLinea: pagoEnLinea),
);

class _DetalleReserva extends ConsumerWidget {
  const _DetalleReserva(this.r, {this.pagoEnLinea = false});

  final ReservaMiembro r;
  final bool pagoEnLinea;

  bool get _sePagaAqui =>
      r.estado == 'pendiente_pago' && pagoEnLinea && r.ordenId != null;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifier = ref.read(cuentaProvider.notifier);
    final inicio = r.iniciaEn == null
        ? null
        : DateTime.tryParse(r.iniciaEn!)?.toLocal();
    final fin = r.terminaEn == null
        ? null
        : DateTime.tryParse(r.terminaEn!)?.toLocal();
    final aviso = r.estado != 'confirmada';
    // Al terminar una acción, se cierra la hoja (la cuenta ya se recargó).
    Future<void> y(Future<void> Function() accion, String exito) async {
      final nav = Navigator.of(context);
      await hacerConAviso(context, accion, exito: exito);
      if (nav.mounted) {
        nav.pop();
      }
    }

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              r.oferta ?? '—',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 4),
            Text(Formato.fechaHora(r.iniciaEn)),
            Text(
              [
                r.sucursal,
                if (r.instructor != null) 'con ${r.instructor}',
                if (r.asiste != null) 'para ${r.asiste}',
              ].whereType<String>().join(' · '),
              style: const TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            const SizedBox(height: 8),
            Text(
              r.estadoTexto,
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: aviso ? TemaAgendaUno.aviso : TemaAgendaUno.exito,
              ),
            ),
            if (r.estado == 'pendiente_pago' && !_sePagaAqui)
              const Padding(
                padding: EdgeInsets.only(top: 4),
                child: Text(
                  'Págala en el negocio o desde la web para confirmarla.',
                  style: TextStyle(
                    color: TemaAgendaUno.textoSuave,
                    fontSize: 13,
                  ),
                ),
              ),
            const SizedBox(height: 16),
            if (inicio != null &&
                (r.estado == 'confirmada' || r.estado == 'pendiente_pago'))
              BotonAgregarCalendario(
                evento: 'reserva-${r.id}',
                titulo: r.oferta ?? 'Reserva',
                inicio: inicio,
                fin: fin,
                lugar: r.sucursal,
              ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                if (_sePagaAqui)
                  FilledButton(
                    onPressed: () => pagarEnLinea(context, ref, r.ordenId!),
                    child: const Text('Pagar'),
                  ),
                if (r.ofrecida)
                  FilledButton(
                    onPressed: () => y(
                      () => notifier.aceptarLugar(r.id),
                      '¡Lugar confirmado!',
                    ),
                    child: const Text('Aceptar lugar'),
                  ),
                if (r.estado == 'confirmada' || r.estado == 'pendiente_pago')
                  OutlinedButton(
                    onPressed: () {
                      Navigator.pop(context);
                      showModalBottomSheet<void>(
                        context: context,
                        isScrollControlled: true,
                        showDragHandle: true,
                        builder: (_) => ReprogramarSheet(r),
                      );
                    },
                    child: const Text('Cambiar horario'),
                  ),
                TextButton(
                  style: TextButton.styleFrom(
                    foregroundColor: TemaAgendaUno.error,
                  ),
                  onPressed: () async {
                    final nav = Navigator.of(context);
                    if (await confirmarCancelacion(context, ref, r.id) &&
                        nav.mounted) {
                      nav.pop();
                    }
                  },
                  child: const Text('Cancelar'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Detalle de una clase disponible: reservar o anotarse en la lista de espera.
Future<void> mostrarDetalleClase(BuildContext context, ClaseMiembro c) =>
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (_) => _DetalleClase(c),
    );

class _DetalleClase extends ConsumerWidget {
  const _DetalleClase(this.c);

  final ClaseMiembro c;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifier = ref.read(cuentaProvider.notifier);
    Future<void> reservar({bool esperar = false}) async {
      final nav = Navigator.of(context);
      await hacerConAviso(
        context,
        () => notifier.reservar(c.id, esperar: esperar),
        exito: esperar ? 'Te anotamos en la lista de espera.' : 'Reservado.',
      );
      if (nav.mounted) {
        nav.pop();
      }
    }

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              c.oferta ?? '—',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 4),
            Text(Formato.fechaHora(c.iniciaEn)),
            Text(
              [
                c.sucursal,
                if (c.instructor != null) 'con ${c.instructor}',
              ].whereType<String>().join(' · '),
              style: const TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            if (c.cobertura != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: EstadoPunto(
                  c.cobertura!.texto,
                  color: colorCobertura(c.cobertura!),
                ),
              ),
            if (c.cobertura?.motivoTexto != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  '${c.cobertura!.motivoTexto!} Consulta los planes en Pagos.',
                  style: const TextStyle(color: TemaAgendaUno.textoSuave),
                ),
              ),
            if (lugaresTexto(c).isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  lugaresTexto(c),
                  style: TextStyle(
                    fontWeight: FontWeight.w600,
                    color: c.llena ? TemaAgendaUno.aviso : TemaAgendaUno.texto,
                  ),
                ),
              ),
            const SizedBox(height: 16),
            if (c.reservable)
              SizedBox(
                width: double.infinity,
                child: c.llena
                    ? OutlinedButton(
                        onPressed: () => reservar(esperar: true),
                        child: const Text('Anotarme en la lista de espera'),
                      )
                    : FilledButton(
                        onPressed: reservar,
                        child: const Text('Reservar'),
                      ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Calificar una clase o cita a la que asistió: 1 a 5 y un comentario opcional.
class CalificarClaseCard extends ConsumerStatefulWidget {
  const CalificarClaseCard(this.r, {super.key});

  final ResenaPendiente r;

  @override
  ConsumerState<CalificarClaseCard> createState() => _CalificarClaseState();
}

class _CalificarClaseState extends ConsumerState<CalificarClaseCard> {
  int _estrellas = 0;
  final _comentario = TextEditingController();
  bool _enviando = false;

  @override
  void dispose() {
    _comentario.dispose();
    super.dispose();
  }

  Future<void> _enviar() async {
    setState(() => _enviando = true);
    final texto = _comentario.text.trim();
    await hacerConAviso(
      context,
      () => ref
          .read(cuentaProvider.notifier)
          .calificar(
            widget.r.reservaId,
            _estrellas,
            texto.isEmpty ? null : texto,
          ),
      exito: '¡Gracias por tu calificación!',
    );
    if (mounted) {
      setState(() => _enviando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final r = widget.r;
    final detalle = [
      if (r.fecha != null) Formato.fechaHora(r.fecha),
      if (r.con != null) r.con!,
    ].join(' · ');

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              r.actividad ?? '—',
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
            if (detalle.isNotEmpty)
              Text(
                detalle,
                style: const TextStyle(color: TemaAgendaUno.textoSuave),
              ),
            Row(
              children: [
                for (var n = 1; n <= 5; n++)
                  IconButton(
                    tooltip: '$n de 5',
                    visualDensity: VisualDensity.compact,
                    onPressed: _enviando
                        ? null
                        : () => setState(() => _estrellas = n),
                    icon: Icon(
                      n <= _estrellas ? Icons.star : Icons.star_border,
                      color: n <= _estrellas
                          ? TemaAgendaUno.aviso
                          : TemaAgendaUno.textoSuave,
                    ),
                  ),
              ],
            ),
            TextField(
              controller: _comentario,
              maxLength: 1000,
              decoration: const InputDecoration(
                hintText: '¿Cómo te fue? (opcional)',
              ),
            ),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: _enviando || _estrellas == 0 ? null : _enviar,
                child: const Text('Enviar'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Un color por acceso de los Inicios (alumno e instructor): los tonos medios de
/// la agenda de la web.
abstract final class TonosAcceso {
  static const reservar = Color(0xFF0070FF);
  static const reservas = Color(0xFF9673DE);
  static const creditos = Color(0xFF12A68B);
  static const pagos = Color(0xFFE07A2E);
  static const pase = Color(0xFF1C9BC7);
  static const expediente = Color(0xFFD6457F);
}

/// Color del punto de la cobertura: incluida en verde, solo con membresía en
/// ámbar, de pago en el primario y no incluida en gris.
Color colorCobertura(CoberturaClase c) => switch (c.estado) {
  'incluida' => TemaAgendaUno.exito,
  'solo_membresia' => TemaAgendaUno.aviso,
  'de_pago' => TemaAgendaUno.acento,
  _ => TemaAgendaUno.textoSuave,
};

/// Un estado como punto de color + texto (sin íconos tintados).
class EstadoPunto extends StatelessWidget {
  const EstadoPunto(this.texto, {super.key, required this.color});

  final String texto;
  final Color color;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Container(
        width: 8,
        height: 8,
        decoration: BoxDecoration(color: color, shape: BoxShape.circle),
      ),
      const SizedBox(width: 6),
      Flexible(
        child: Text(texto, style: const TextStyle(fontWeight: FontWeight.w500)),
      ),
    ],
  );
}
