import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/calendario/agregar_calendario.dart';
import '../../../core/formato.dart';
import '../../../core/hora_negocio.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/network/participante_en_uso.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/eventos_cuenta.dart';
import 'apartados_clase.dart';
import 'cambiar_lugar_sheet.dart';
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
    child: Text(texto, style: TextStyle(color: context.colores.textoSuave)),
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
          Padding(
            padding: const EdgeInsets.all(14),
            child: _contenido(context),
          ),
        ],
      ),
    ),
  );

  Widget _contenido(BuildContext context) => Column(
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
        Icon(icono, color: context.colores.textoSuave),
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
          color: atencion ? context.colores.aviso : context.colores.textoSuave,
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
      color: context.colores.aviso.withValues(alpha: 0.08),
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
              color: context.colores.aviso.withValues(alpha: 0.35),
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
            style: TextStyle(color: context.colores.textoSuave),
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
class PorPagarTile extends ConsumerStatefulWidget {
  const PorPagarTile(
    this.o, {
    super.key,
    this.pagoEnLinea = false,
    this.pagoAutomatico = false,
  });

  final OrdenPorPagar o;
  final bool pagoEnLinea;

  /// ¿Se puede dejar el pago automático al pagar? (si se renueva; como en la web).
  final bool pagoAutomatico;

  @override
  ConsumerState<PorPagarTile> createState() => _PorPagarTileState();
}

class _PorPagarTileState extends ConsumerState<PorPagarTile> {
  var _domiciliar = false;
  var _pagando = false;

  Future<void> _pagar(String metodo) async {
    setState(() => _pagando = true);
    await pagarEnLinea(
      context,
      ref,
      widget.o.id,
      metodo: metodo,
      // Solo si se puede domiciliar al pagar y la orden se renueva.
      domiciliar: widget.pagoAutomatico && widget.o.recurrente && _domiciliar,
    );
    if (mounted) {
      setState(() => _pagando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final o = widget.o;
    final pagoEnLinea = widget.pagoEnLinea;
    // Por una persona a su cargo, el pago automático va a la tarjeta del
    // responsable (ADR 0129).
    final aCargo = ref.watch(participanteEnUsoProvider) != null;
    return Card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ListTile(
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
                    onPressed: _pagando ? null : () => _pagar('tarjeta'),
                    child: Text(_pagando ? 'Abriendo…' : 'Pagar'),
                  )
                : null,
          ),
          // Ya inició un pago que espera confirmación: para no pagar dos veces.
          if (o.avisoEnProceso != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Text(
                o.avisoEnProceso!,
                key: const Key('pago-en-proceso'),
                style: TextStyle(
                  fontSize: 12,
                  color: context.colores.textoSuave,
                ),
              ),
            ),
          if (pagoEnLinea)
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 4),
              child: Wrap(
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  TextButton(
                    onPressed: _pagando ? null : () => _pagar('oxxo'),
                    child: const Text('o paga en efectivo en OXXO'),
                  ),
                  if (widget.pagoAutomatico && o.recurrente)
                    Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Checkbox(
                          value: _domiciliar,
                          onChanged: _pagando
                              ? null
                              : (v) => setState(() => _domiciliar = v ?? false),
                        ),
                        Flexible(
                          child: Text(
                            aCargo
                                ? 'Cobrar automáticamente cada renovación a tu tarjeta'
                                : 'Cobrar automáticamente cada renovación',
                            style: const TextStyle(fontSize: 12),
                          ),
                        ),
                      ],
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Detalle de una reserva suya: agregar a mi calendario, pagar, aceptar el lugar,
/// cambiar horario, elegir o cambiar su lugar numerado o cancelar.
Future<void> mostrarDetalleReserva(
  BuildContext context,
  WidgetRef ref,
  ReservaMiembro r, {
  bool pagoEnLinea = false,
}) async {
  // «Pagar» cierra la hoja con su orden y el pago se abre desde aquí.
  final aPagar = await showModalBottomSheet<String>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) => _DetalleReserva(r, pagoEnLinea: pagoEnLinea),
  );
  if (aPagar != null && context.mounted) {
    await pagarEnLinea(context, ref, aPagar);
  }
}

class _DetalleReserva extends ConsumerStatefulWidget {
  const _DetalleReserva(this.r, {this.pagoEnLinea = false});

  final ReservaMiembro r;
  final bool pagoEnLinea;

  @override
  ConsumerState<_DetalleReserva> createState() => _DetalleReservaState();
}

class _DetalleReservaState extends ConsumerState<_DetalleReserva> {
  /// Mientras se acepta el lugar: un segundo toque no manda otra petición (y no
  /// muestra un error después del «¡Lugar confirmado!»).
  var _ocupado = false;

  ReservaMiembro get r => widget.r;

  bool get _sePagaAqui =>
      r.estado == 'pendiente_pago' && widget.pagoEnLinea && r.ordenId != null;

  @override
  Widget build(BuildContext context) {
    final notifier = ref.read(cuentaProvider.notifier);
    // Las horas que se muestran son las de su sede o, si el API no la manda, las
    // del negocio (no las del teléfono).
    final desfase =
        r.desfaseMin ?? ref.watch(sesionProvider)?.desfaseNegocioMin;
    // Instantes (no horas de pared): el calendario del teléfono los pasa a UTC y
    // los muestra en su zona.
    final inicio = r.iniciaEn == null
        ? null
        : DateTime.tryParse(r.iniciaEn!)?.toLocal();
    final hora = _etiquetaHoraSede(r.desfaseMin, inicio, r.sucursal);
    final fin = r.terminaEn == null
        ? null
        : DateTime.tryParse(r.terminaEn!)?.toLocal();
    // Hasta cuándo se acepta un lugar ofrecido (en la hora del negocio).
    final expira = HoraNegocio.pared(r.ofertaExpiraEn, desfaseMin: desfase);
    final aviso = r.estado != 'confirmada';
    // Con anticipo (ADR 0161): lo que ya pagó y lo que paga en el negocio.
    final anticipo = r.cita?.textoAnticipo(
      ref.watch(sesionProvider)?.moneda ?? 'MXN',
    );
    // Cuánto paga al tocar «Pagar»: la orden de la reserva entre las por pagar.
    final porPagar = _sePagaAqui
        ? ref
              .watch(cuentaProvider)
              .value
              ?.porPagar
              .where((o) => o.id == r.ordenId)
              .firstOrNull
        : null;
    // Al terminar una acción, se cierra la hoja (la cuenta ya se recargó).
    Future<void> y(Future<void> Function() accion, String exito) async {
      if (_ocupado) {
        return;
      }
      setState(() => _ocupado = true);
      final nav = Navigator.of(context);
      await hacerConAviso(context, accion, exito: exito);
      if (mounted) {
        setState(() => _ocupado = false);
      }
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
            Text(Formato.fechaHora(r.iniciaEn, desfaseMin: desfase)),
            if (hora != null)
              Text(hora, style: TextStyle(color: context.colores.textoSuave)),
            Text(
              [
                r.sucursal,
                if (r.instructor != null) 'con ${r.instructor}',
                if (r.asiste != null) 'para ${r.asiste}',
              ].whereType<String>().join(' · '),
              style: TextStyle(color: context.colores.textoSuave),
            ),
            // Clase en línea (ADR 0184): solo https.
            if (r.enlaceEnLinea != null &&
                r.enlaceEnLinea!.startsWith('https://'))
              TextButton.icon(
                key: const Key('entrar-en-linea'),
                style: TextButton.styleFrom(padding: EdgeInsets.zero),
                icon: const Icon(Icons.videocam_outlined, size: 18),
                label: Text(
                  r.esCita
                      ? 'Entrar a la cita en línea'
                      : 'Entrar a la clase en línea',
                ),
                onPressed: () => launchUrl(
                  Uri.parse(r.enlaceEnLinea!),
                  mode: LaunchMode.externalApplication,
                ),
              ),
            // Dónde es (M06): cómo llegar a la sede, en clases y en citas.
            if (r.mapaUrl != null)
              TextButton.icon(
                key: const Key('como-llegar'),
                style: TextButton.styleFrom(padding: EdgeInsets.zero),
                icon: const Icon(Icons.place_outlined, size: 18),
                label: const Text('Cómo llegar'),
                onPressed: () => launchUrl(
                  Uri.parse(r.mapaUrl!),
                  mode: LaunchMode.externalApplication,
                ),
              ),
            const SizedBox(height: 8),
            Text(
              r.estadoTexto,
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: aviso ? context.colores.aviso : context.colores.exito,
              ),
            ),
            if (r.lugar != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('Tu lugar: ${r.lugar}'),
              ),
            // Un lugar ofrecido se acepta a tiempo o pasa al siguiente.
            if (r.estado == 'ofrecida' && expira != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'Acéptalo antes de las ${Formato.hora(expira)}.',
                  style: TextStyle(color: context.colores.aviso),
                ),
              ),
            if (r.estado == 'pendiente_pago' && !_sePagaAqui)
              Padding(
                padding: EdgeInsets.only(top: 4),
                child: Text(
                  'Págala en el negocio o desde la web para confirmarla.',
                  style: TextStyle(
                    color: context.colores.textoSuave,
                    fontSize: 13,
                  ),
                ),
              ),
            if (anticipo != null)
              Padding(
                key: const Key('anticipo-pagado'),
                padding: const EdgeInsets.only(top: 4),
                child: Text(anticipo),
              ),
            // Productos para recoger en la clase (ADR 0194), antes de que empiece.
            if (!r.esCita &&
                r.estado == 'confirmada' &&
                r.sesionId != null &&
                inicio != null &&
                inicio.isAfter(DateTime.now()))
              ApartadosClase(sesionId: r.sesionId!),
            const SizedBox(height: 16),
            if (inicio != null &&
                (r.estado == 'confirmada' || r.estado == 'pendiente_pago'))
              BotonAgregarCalendario(
                evento: 'reserva-${r.id}',
                titulo: r.oferta ?? (r.esCita ? 'Cita' : 'Reserva'),
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
                    key: const Key('pagar-reserva'),
                    // Cierra la hoja con la orden a pagar ([mostrarDetalleReserva]
                    // abre el pago): un segundo toque no abre otro y al volver no
                    // queda la reserva vieja.
                    onPressed: () => Navigator.pop(context, r.ordenId),
                    child: Text(
                      porPagar == null
                          ? 'Pagar'
                          : 'Pagar ${Formato.dinero(porPagar.totalMinor, porPagar.moneda)}',
                    ),
                  ),
                if (r.ofrecida)
                  FilledButton(
                    key: const Key('aceptar-lugar'),
                    onPressed: _ocupado
                        ? null
                        : () => y(
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
                // Lugares numerados (B06): elegir o cambiar el suyo antes de la
                // clase.
                if (!r.esCita &&
                    r.lugares > 0 &&
                    r.sesionId != null &&
                    (r.estado == 'confirmada' ||
                        r.estado == 'pendiente_pago') &&
                    inicio != null &&
                    inicio.isAfter(DateTime.now()))
                  OutlinedButton(
                    key: const Key('cambiar-lugar'),
                    onPressed: () {
                      Navigator.pop(context);
                      showModalBottomSheet<void>(
                        context: context,
                        isScrollControlled: true,
                        showDragHandle: true,
                        builder: (_) => CambiarLugarSheet(r),
                      );
                    },
                    child: Text(
                      r.lugar == null ? 'Elegir mi lugar' : 'Cambiar mi lugar',
                    ),
                  ),
                TextButton(
                  style: TextButton.styleFrom(
                    foregroundColor: context.colores.error,
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

/// «Hora de Roma Norte» cuando el teléfono está en otra zona que la sede (sus
/// horas son siempre las de la sede); null si es la misma o el API no la manda.
String? _etiquetaHoraSede(int? desfaseSede, DateTime? inicio, String? sede) =>
    desfaseSede == null ||
        inicio == null ||
        desfaseSede == HoraNegocio.desfaseTelefono(inicio)
    ? null
    : HoraNegocio.etiqueta([sede]);

/// Detalle de una clase disponible: reservar o anotarse en la lista de espera.
Future<void> mostrarDetalleClase(BuildContext context, ClaseMiembro c) =>
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (_) => _DetalleClase(c),
    );

class _DetalleClase extends ConsumerStatefulWidget {
  const _DetalleClase(this.c);

  final ClaseMiembro c;

  @override
  ConsumerState<_DetalleClase> createState() => _DetalleClaseState();
}

class _DetalleClaseState extends ConsumerState<_DetalleClase> {
  /// El lugar numerado que eligió (opcional; B06).
  int? _lugar;

  /// Mientras se reserva: un segundo toque no manda otra reserva (el servidor la
  /// rechazaría y se vería un error después del «Reservado.»).
  var _reservando = false;

  ClaseMiembro get c => widget.c;

  /// Pase de invitado (ADR 0182): aparta un lugar para alguien que no es cliente.
  Future<void> _invitar(int pases) async {
    final messenger = ScaffoldMessenger.of(context);
    final notifier = ref.read(cuentaProvider.notifier);
    final nombre = await showDialog<String>(
      context: context,
      builder: (context) => const _NombreInvitado(),
    );
    if (nombre == null || nombre.trim().length < 2) {
      return;
    }
    try {
      await notifier.invitar(c.id, nombre.trim());
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            'Apartamos un lugar para ${nombre.trim()}. Si no puede venir, '
            'cancélalo antes de la clase y recuperas tu pase.',
          ),
        ),
      );
    } on DioException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
    }
    if (mounted) {
      ref.invalidate(antesDeReservarProvider(c.id));
    }
  }

  @override
  Widget build(BuildContext context) {
    final notifier = ref.read(cuentaProvider.notifier);
    // En la hora de su sede o, sin ella, la del negocio.
    final desfase =
        c.desfaseMin ?? ref.watch(sesionProvider)?.desfaseNegocioMin;
    final inicio = c.iniciaEn == null
        ? null
        : DateTime.tryParse(c.iniciaEn!)?.toLocal();
    final hora = _etiquetaHoraSede(c.desfaseMin, inicio, c.sucursal);
    // Lo que pasará al reservar (M05); sin respuesta, se puede intentar igual.
    final antes = ref.watch(antesDeReservarProvider(c.id)).value;
    // Si alguien tomó el lugar (u otra cosa cambió), se dice y se vuelve a pedir la
    // vista previa: nunca se cambia a otro lugar en silencio.
    Future<void> reservar({bool esperar = false}) async {
      if (_reservando) {
        return;
      }
      setState(() => _reservando = true);
      final nav = Navigator.of(context);
      final messenger = ScaffoldMessenger.of(context);
      try {
        final r = await notifier.reservar(
          c.id,
          esperar: esperar,
          lugar: _lugar,
        );
        // Una clase de pago queda apartada: se paga para confirmar el lugar.
        final porPagar = r.estado == 'pendiente_pago' && r.ordenId != null;
        messenger.showSnackBar(
          SnackBar(
            content: Text(
              esperar
                  ? 'Te anotamos en la lista de espera.'
                  : porPagar
                  ? 'Apartado: págalo para confirmar tu lugar.'
                  : 'Reservado.',
            ),
          ),
        );
        if (porPagar && context.mounted) {
          await pagarEnLinea(context, ref, r.ordenId!);
        }
        if (nav.mounted) {
          nav.pop();
        }
      } on DioException catch (e) {
        messenger.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
        if (mounted) {
          setState(() => _lugar = null);
          ref.invalidate(antesDeReservarProvider(c.id));
        }
      } finally {
        if (mounted) {
          setState(() => _reservando = false);
        }
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
            Text(Formato.fechaHora(c.iniciaEn, desfaseMin: desfase)),
            if (hora != null)
              Text(hora, style: TextStyle(color: context.colores.textoSuave)),
            Text(
              [
                c.sucursal,
                if (c.instructor != null) 'con ${c.instructor}',
              ].whereType<String>().join(' · '),
              style: TextStyle(color: context.colores.textoSuave),
            ),
            if (c.cobertura != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: EstadoPunto(
                  c.cobertura!.texto,
                  color: colorCobertura(context, c.cobertura!),
                ),
              ),
            if (c.cobertura?.motivoTexto != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  '${c.cobertura!.motivoTexto!} Consulta los planes en Pagos.',
                  style: TextStyle(color: context.colores.textoSuave),
                ),
              ),
            if (lugaresTexto(c).isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  lugaresTexto(c),
                  style: TextStyle(
                    fontWeight: FontWeight.w600,
                    color: c.llena
                        ? context.colores.aviso
                        : context.colores.texto,
                  ),
                ),
              ),
            if (antes != null)
              for (final dato in [
                antes.motivoTexto,
                antes.consumoTexto,
                antes.cancelacionTexto(DateTime.now(), desfaseMin: desfase),
                if (antes.queLlevar != null) 'Qué llevar: ${antes.queLlevar}',
              ])
                if (dato != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Text(dato),
                  ),
            if (antes != null &&
                antes.lugaresTotal > 0 &&
                antes.resultado == 'reserva') ...[
              const SizedBox(height: 12),
              const Text(
                'Elige tu lugar (opcional)',
                style: TextStyle(fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 6),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (var n = 1; n <= antes.lugaresTotal; n++)
                    Semantics(
                      label: antes.lugaresOcupados.contains(n)
                          ? 'Lugar $n, ocupado'
                          : _lugar == n
                          ? 'Lugar $n, el tuyo'
                          : 'Lugar $n, libre',
                      child: ChoiceChip(
                        label: Text(
                          '$n',
                          style: antes.lugaresOcupados.contains(n)
                              ? const TextStyle(
                                  decoration: TextDecoration.lineThrough,
                                )
                              : null,
                        ),
                        selected: _lugar == n,
                        onSelected: antes.lugaresOcupados.contains(n)
                            ? null
                            : (si) => setState(() => _lugar = si ? n : null),
                      ),
                    ),
                ],
              ),
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  _lugar == null
                      ? 'Tachados: ocupados. Si no eliges, el negocio te asigna uno.'
                      : 'Elegiste el lugar $_lugar.',
                  style: TextStyle(
                    fontSize: 12,
                    color: context.colores.textoSuave,
                  ),
                ),
              ),
            ],
            if (antes != null && antes.pasesInvitado > 0)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: TextButton(
                  onPressed: () => _invitar(antes.pasesInvitado),
                  child: Text(
                    antes.pasesInvitado == 1
                        ? 'Invitar a alguien (te queda 1 pase este mes)'
                        : 'Invitar a alguien (te quedan ${antes.pasesInvitado} pases este mes)',
                  ),
                ),
              ),
            const SizedBox(height: 16),
            if (antes != null && !antes.puedeReservar)
              const SizedBox.shrink()
            else if (antes != null)
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  key: const Key('confirmar-reserva'),
                  onPressed: _reservando
                      ? null
                      : () => reservar(esperar: antes.esListaEspera),
                  child: Text(
                    antes.esListaEspera
                        ? 'Anotarme en la lista de espera'
                        : antes.precioMinor != null
                        ? 'Reservar y pagar'
                        : 'Confirmar reserva',
                  ),
                ),
              )
            else if (c.reservable)
              SizedBox(
                width: double.infinity,
                child: c.llena
                    ? OutlinedButton(
                        onPressed: _reservando
                            ? null
                            : () => reservar(esperar: true),
                        child: const Text('Anotarme en la lista de espera'),
                      )
                    : FilledButton(
                        onPressed: _reservando ? null : reservar,
                        child: const Text('Reservar'),
                      ),
              ),
          ],
        ),
      ),
    );
  }
}

/// El nombre del invitado (ADR 0182).
class _NombreInvitado extends StatefulWidget {
  const _NombreInvitado();

  @override
  State<_NombreInvitado> createState() => _NombreInvitadoState();
}

class _NombreInvitadoState extends State<_NombreInvitado> {
  final _nombre = TextEditingController();

  @override
  void dispose() {
    _nombre.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Invitar a alguien'),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        TextField(
          controller: _nombre,
          autofocus: true,
          maxLength: 120,
          textCapitalization: TextCapitalization.words,
          decoration: const InputDecoration(labelText: 'Nombre de tu invitado'),
        ),
        Text(
          'Ocupa un lugar en la clase y usa uno de los pases de tu plan.',
          style: TextStyle(fontSize: 12, color: context.colores.textoSuave),
        ),
      ],
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.of(context).pop(),
        child: const Text('Cancelar'),
      ),
      FilledButton(
        onPressed: () => Navigator.of(context).pop(_nombre.text),
        child: const Text('Apartar su lugar'),
      ),
    ],
  );
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
    final desfase =
        r.desfaseMin ?? ref.watch(sesionProvider)?.desfaseNegocioMin;
    final detalle = [
      if (r.fecha != null) Formato.fechaHora(r.fecha, desfaseMin: desfase),
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
                style: TextStyle(color: context.colores.textoSuave),
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
                          ? context.colores.aviso
                          : context.colores.textoSuave,
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
Color colorCobertura(BuildContext context, CoberturaClase c) =>
    switch (c.estado) {
      'incluida' => context.colores.exito,
      'solo_membresia' => context.colores.aviso,
      'de_pago' => context.colores.acento,
      _ => context.colores.textoSuave,
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
