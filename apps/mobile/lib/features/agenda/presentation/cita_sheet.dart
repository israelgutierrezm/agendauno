import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import 'agenda_screen.dart';
import 'reprogramar_cita_sheet.dart';

/// Abre la hoja de una cita: quién, qué, a qué hora y cómo va (su atención y su
/// pago, como los calcula el servidor), lo que el cliente pidió, su contacto y su
/// historial, con las acciones de recepción (llegó, no asistió, cobrar en caja,
/// reprogramar, cancelar). Lo que mueve dinero o créditos se confirma antes, como
/// en la web.
///
/// La hoja lee la cita de la agenda del día: abierta desde el Inicio o Mis clases
/// (una cita de otro día), la agenda pasa al día de la cita en su sucursal, como al
/// agendar una. Si no, diría que la cita «ya no está en la agenda».
Future<void> mostrarHojaCita(BuildContext context, SesionAgenda sesion) {
  final dia = sesion.iniciaLocal;
  final contenedor = ProviderScope.containerOf(context, listen: false);
  final actual = contenedor.read(fechaAgendaProvider);
  if (actual.year != dia.year ||
      actual.month != dia.month ||
      actual.day != dia.day) {
    contenedor.read(fechaAgendaProvider.notifier).elegir(dia);
  }
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) => _HojaCita(sesionId: sesion.id),
  );
}

String _hhmm(DateTime d) =>
    '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

/// Pregunta antes de hacer algo que no se deshace solo. Devuelve si se aceptó.
Future<bool> _confirmar(
  BuildContext context, {
  required String titulo,
  required String mensaje,
  required String aceptar,
  bool peligro = false,
}) async {
  final si = await showDialog<bool>(
    context: context,
    builder: (contexto) => AlertDialog(
      title: Text(titulo),
      content: Text(mensaje),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(contexto).pop(false),
          child: const Text('Volver'),
        ),
        TextButton(
          style: peligro
              ? TextButton.styleFrom(foregroundColor: context.colores.error)
              : null,
          onPressed: () => Navigator.of(contexto).pop(true),
          child: Text(aceptar),
        ),
      ],
    ),
  );
  return si == true;
}

class _HojaCita extends ConsumerStatefulWidget {
  const _HojaCita({required this.sesionId});

  final String sesionId;

  @override
  ConsumerState<_HojaCita> createState() => _HojaCitaState();
}

class _HojaCitaState extends ConsumerState<_HojaCita> {
  var _ocupado = false;
  var _metodo = 'efectivo';

  /// La clave del intento de cobro: la misma en cada reintento (un cobro que el
  /// servidor ya registró no se repite) hasta que sale bien o cambia la forma.
  String? _claveCobro;

  /// Ejecuta la acción (la agenda se recarga al terminar). Con [cerrar], la hoja se
  /// cierra si salió bien: una cita cancelada ya no vuelve en la agenda.
  Future<void> _hacer(
    Future<void> Function(AgendaController c) accion,
    String ok, {
    bool cerrar = false,
  }) async {
    setState(() => _ocupado = true);
    final mensajero = ScaffoldMessenger.of(context);
    final navegador = Navigator.of(context);
    try {
      await accion(ref.read(agendaProvider.notifier));
      mensajero.showSnackBar(SnackBar(content: Text(ok)));
      if (cerrar && mounted) {
        navegador.pop();
      }
    } on DioException catch (e) {
      mensajero.showSnackBar(
        SnackBar(
          content: Text(mensajeDeError(e, porDefecto: 'No se pudo completar.')),
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _ocupado = false);
      }
    }
  }

  /// Un cobro en caja no se deshace: se confirma monto, forma y a quién. Con
  /// anticipo (ADR 0161) se cobra el saldo y se dice cuánto ya pagó.
  Future<void> _cobrar(SesionAgenda s, String moneda) async {
    final cita = s.cita!;
    final cliente = cita.cliente ?? 'el cliente';
    String dinero(int minor) => Formato.dinero(minor, moneda);
    final saldo = s.porCobrarMinor;
    final monto = saldo != null ? dinero(saldo) : '';
    final desglose = cita.anticipoMinor > 0 && cita.totalMinor != null
        ? ' Total ${dinero(cita.totalMinor!)}, anticipo pagado '
              '${dinero(cita.anticipoMinor)}.'
        : '';
    if (!await _confirmar(
      context,
      titulo: 'Cobrar cita',
      mensaje:
          '¿Registrar el cobro de $monto en '
          '${HechoCita.metodo(_metodo)} a $cliente?$desglose La cita queda pagada.',
      aceptar: 'Cobrar $monto'.trim(),
    )) {
      return;
    }
    if (!mounted) {
      return;
    }
    setState(() => _ocupado = true);
    final mensajero = ScaffoldMessenger.of(context);
    try {
      _claveCobro ??=
          'app-${cita.reservaId}-${DateTime.now().microsecondsSinceEpoch}';
      final cobrada = await ref
          .read(agendaProvider.notifier)
          .cobrar(s, _metodo, clave: _claveCobro);
      // El servidor respondió: el siguiente cobro (otra cosa) lleva otra clave.
      _claveCobro = null;
      if (cobrada) {
        mensajero.showSnackBar(const SnackBar(content: Text('Cita cobrada.')));
      }
    } on DioException catch (e) {
      mensajero.showSnackBar(
        SnackBar(
          content: Text(mensajeDeError(e, porDefecto: 'No se pudo cobrar.')),
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _ocupado = false);
      }
    }
  }

  /// Marcar o corregir la asistencia mueve créditos: se confirma con su efecto a la
  /// vista. Llegó ↔ llegó tarde no los mueve: se guarda sin preguntar.
  Future<void> _marcar(
    SesionAgenda s,
    String asistencia, {
    bool retardo = false,
  }) async {
    final cita = s.cita!;
    final cliente = cita.cliente ?? 'el cliente';
    String nombre(String e) => e == 'presente' ? 'Llegó' : 'No asistió';
    final ok = cita.asistencia != null && cita.asistencia != asistencia
        ? 'Asistencia corregida.'
        : asistencia == 'presente'
        ? (retardo ? 'Se registró que llegó tarde.' : 'Llegada registrada.')
        : 'Se marcó como no asistió.';
    if (cita.asistencia != asistencia) {
      // Al marcar que no vino, el efecto exacto según su política (el crédito, o
      // el anticipo o el pago que no se devuelven), como en la web.
      final efecto = cita.asistencia == null && asistencia == 'ausente'
          ? await ref.read(agendaProvider.notifier).efectoDeInasistencia(s)
          : null;
      if (!mounted) {
        return;
      }
      final mensaje = cita.asistencia != null
          ? '$cliente está marcado como «${nombre(cita.asistencia!)}». '
                '¿Cambiarlo a «${nombre(asistencia)}»? Si aplica, el crédito se '
                'ajusta en su saldo.'
          : asistencia == 'presente'
          ? '¿Marcar que $cliente llegó${retardo ? ' tarde' : ''} a su cita?'
          : '¿Marcar que $cliente no asistió? '
                '${efecto?.texto ?? 'Según la política del negocio, puede perder '
                        'el crédito o el pago de la cita.'}';
      if (!await _confirmar(
        context,
        titulo: 'Asistencia',
        mensaje: mensaje,
        aceptar: retardo ? 'Llegó tarde' : nombre(asistencia),
        peligro: asistencia == 'ausente',
      )) {
        return;
      }
    }
    if (!mounted) {
      return;
    }
    await _hacer(
      (c) => asistencia == 'presente'
          ? c.marcarLlegada(s, retardo: retardo)
          : c.marcarNoAsistio(s),
      ok,
    );
  }

  /// Cancelar libera el horario y no se deshace: se elige quién cancela (el
  /// negocio no penaliza; si lo pidió el cliente, aplica su política) y se ve el
  /// efecto antes de confirmar, como en la web.
  Future<void> _cancelar(SesionAgenda s) async {
    final controlador = ref.read(agendaProvider.notifier);
    final miembro = ref.read(sesionProvider)?.terminologia.miembro ?? 'cliente';
    final por = await showDialog<String>(
      context: context,
      builder: (_) => _DialogoCancelar(
        sesion: s,
        miembro: miembro.toLowerCase(),
        efecto: (por) => controlador.efectoDeCancelar(s, por: por),
      ),
    );
    if (por == null || !mounted) {
      return;
    }
    await _hacer(
      (c) => c.cancelar(s, por: por),
      'Cita cancelada; el horario quedó libre.',
      cerrar: true,
    );
  }

  /// Mover la cita a otra hora (y, si se quiere, con otro profesional). Si se movió,
  /// la hoja se cierra: la cita pudo irse a otro día.
  Future<void> _reprogramar(SesionAgenda s) async {
    final navegador = Navigator.of(context);
    final equipo = ref.read(agendaProvider).value?.profesionales ?? const [];
    final movida = await mostrarReprogramarCita(context, s, equipo);
    if (movida && mounted) {
      navegador.pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final estadoAgenda = ref.watch(agendaProvider);
    final agenda = estadoAgenda.value;
    // Solo se ofrece lo que el servidor permitiría (un instructor no cobra ni cancela).
    final sesion = ref.watch(sesionProvider);
    final puedeCobrar = sesion?.puede('ordenes.gestionar') ?? false;
    final puedeMarcar = sesion?.puede('asistencia.marcar') ?? false;
    final puedeCancelar = sesion?.puede('reservas.gestionar') ?? false;
    final verHistorial = sesion?.puede('reservas.ver') ?? false;
    final s = agenda?.sesiones
        .where((x) => x.id == widget.sesionId)
        .firstOrNull;
    if (s == null || s.cita == null) {
      // Cargada la agenda sin esta cita (la cancelaron o la movieron desde otro
      // lado), se dice: nunca un indicador girando para siempre.
      final cargando = agenda == null || estadoAgenda.isLoading;
      return SizedBox(
        height: 120,
        child: Center(
          child: cargando
              ? const CircularProgressIndicator()
              : const Text('Esta cita ya no está en la agenda.'),
        ),
      );
    }
    final cita = s.cita!;
    final estado = s.estadoCita;
    final pago = s.porCobrar ? cita.estadoPago : null;
    final tono = TonoServicio.de(s.ofertaId).en(context.colores);
    // Cancelada, completada o sin asistencia: ya no hay acciones.
    final activa = !(estado?.cerrada ?? false);
    // Lo que cuesta (el total de su orden) y lo que falta cobrar (el saldo, si
    // pagó anticipo), con centavos y en su moneda.
    final moneda = s.monedaDe(sesion?.moneda ?? 'MXN');
    final monto = s.montoMinor != null
        ? Formato.dinero(s.montoMinor!, moneda)
        : null;
    final porCobrar = s.porCobrarMinor != null
        ? Formato.dinero(s.porCobrarMinor!, moneda)
        : null;
    // La asistencia se registra desde unos minutos antes (ADR 0101).
    final abierta = s.asistenciaAbierta(DateTime.now());
    final marcable = puedeMarcar && cita.marcable && abierta;
    // Reprogramar o cancelar: aún sin asistencia marcada.
    final cancelable = puedeCancelar && cita.asistencia == null;

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Wrap(
              spacing: 8,
              children: [
                if (estado != null)
                  Chip(
                    label: Text(
                      estado.etiqueta,
                      style: TextStyle(
                        color: estiloEstado(context.colores, estado).$2,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    backgroundColor: estiloEstado(context.colores, estado).$1,
                    side: BorderSide.none,
                  ),
                if (pago != null)
                  Chip(
                    label: Text(
                      pago.etiqueta,
                      style: TextStyle(
                        color: estiloPago(context.colores).$2,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    backgroundColor: estiloPago(context.colores).$1,
                    side: BorderSide.none,
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                CircleAvatar(
                  radius: 26,
                  backgroundColor: tono.fondo,
                  child: Text(
                    Profesional(id: '', nombre: cita.cliente ?? '?').iniciales,
                    style: TextStyle(
                      color: tono.tinta,
                      fontWeight: FontWeight.w800,
                      fontSize: 17,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        cita.cliente ?? 'Sin cliente',
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      Text(
                        '${_hhmm(s.iniciaLocal)}–${_hhmm(s.terminaLocal)} · ${s.oferta ?? '—'}',
                        style: TextStyle(
                          color: context.colores.textoSuave,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      // El teléfono está en otra zona: es la hora de la sucursal.
                      if (s.etiquetaHora() case final etiqueta?)
                        Text(
                          etiqueta,
                          key: const Key('hora-de-la-sucursal'),
                          style: TextStyle(
                            fontSize: 12,
                            color: context.colores.textoSuave,
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              'Con ${s.instructor ?? '—'}${monto != null ? ' · $monto' : ''}',
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            // Si la agendó para otra persona, quién viene (ADR 0068).
            if (cita.asiste != null && cita.asiste != cita.cliente) ...[
              const SizedBox(height: 4),
              Text('Asiste: ${cita.asiste}'),
            ],
            if (cita.nota != null) ...[
              const SizedBox(height: 10),
              Container(
                key: const Key('nota-cliente'),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: context.colores.fondo,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Nota del cliente',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: context.colores.textoSuave,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(cita.nota!),
                  ],
                ),
              ),
            ],
            if (cita.telefono != null || cita.email != null)
              _Contacto(telefono: cita.telefono, email: cita.email),
            if (activa) ...[
              const SizedBox(height: 16),
              if (s.porCobrar && puedeCobrar)
                Row(
                  children: [
                    DropdownButton<String>(
                      value: _metodo,
                      onChanged: _ocupado
                          ? null
                          : (v) => setState(() {
                              _metodo = v ?? 'efectivo';
                              // Otra forma de pago es otro intento.
                              _claveCobro = null;
                            }),
                      items: const [
                        DropdownMenuItem(
                          value: 'efectivo',
                          child: Text('Efectivo'),
                        ),
                        DropdownMenuItem(
                          value: 'transferencia',
                          child: Text('Transferencia'),
                        ),
                        DropdownMenuItem(
                          value: 'tarjeta',
                          child: Text('Tarjeta (terminal)'),
                        ),
                      ],
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton(
                        onPressed: _ocupado ? null : () => _cobrar(s, moneda),
                        child: Text('Cobrar ${porCobrar ?? ''}'.trim()),
                      ),
                    ),
                  ],
                ),
              const SizedBox(height: 8),
              if (puedeMarcar && !abierta && cita.asistencia == null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    'La asistencia se registra desde las '
                    '${_hhmm(s.enSucursal(s.asistenciaDesde!))}.',
                    key: const Key('asistencia-abre'),
                    style: TextStyle(color: context.colores.textoSuave),
                  ),
                ),
              if (puedeMarcar)
                Row(
                  children: [
                    if (cita.asistencia != 'presente')
                      Expanded(
                        child: FilledButton.tonal(
                          onPressed: _ocupado || !marcable
                              ? null
                              : () => _marcar(s, 'presente'),
                          child: const Text('Llegó'),
                        ),
                      ),
                    if (cita.asistencia != 'presente') const SizedBox(width: 8),
                    // Llegó, pero tarde: cuenta como que llegó.
                    if (cita.asistencia != 'presente' || !cita.retardo)
                      Expanded(
                        child: OutlinedButton(
                          onPressed: _ocupado || !marcable
                              ? null
                              : () => _marcar(s, 'presente', retardo: true),
                          child: const Text('Llegó tarde'),
                        ),
                      ),
                    if (cita.asistencia != 'presente' || !cita.retardo)
                      const SizedBox(width: 8),
                    if (cita.asistencia != 'ausente')
                      Expanded(
                        child: OutlinedButton(
                          onPressed: _ocupado || !marcable
                              ? null
                              : () => _marcar(s, 'ausente'),
                          child: const Text('No asistió'),
                        ),
                      ),
                  ],
                ),
              // Mover o cancelar: con el mismo permiso y antes de marcar asistencia.
              if (cancelable)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          key: const Key('reprogramar'),
                          icon: const Icon(Icons.event_repeat, size: 18),
                          label: const Text('Reprogramar'),
                          onPressed: _ocupado ? null : () => _reprogramar(s),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: TextButton(
                          onPressed: _ocupado ? null : () => _cancelar(s),
                          style: TextButton.styleFrom(
                            foregroundColor: context.colores.error,
                          ),
                          child: const Text('Cancelar cita'),
                        ),
                      ),
                    ],
                  ),
                ),
            ],
            if (verHistorial) _HistorialCita(sesion: s, moneda: moneda),
          ],
        ),
      ),
    );
  }
}

/// Contacto del cliente: llamar, WhatsApp o correo (solo si se puede ver).
class _Contacto extends StatelessWidget {
  const _Contacto({this.telefono, this.email});

  final String? telefono;
  final String? email;

  @override
  Widget build(BuildContext context) {
    final digitos = telefono?.replaceAll(RegExp(r'\D'), '') ?? '';
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Wrap(
        spacing: 4,
        children: [
          if (digitos.isNotEmpty) ...[
            TextButton.icon(
              icon: const Icon(Icons.call_outlined, size: 18),
              label: Text(telefono!),
              onPressed: () => launchUrl(Uri.parse('tel:$digitos')),
            ),
            TextButton(
              onPressed: () => launchUrl(
                Uri.parse('https://wa.me/$digitos'),
                mode: LaunchMode.externalApplication,
              ),
              child: const Text('WhatsApp'),
            ),
          ],
          if (email != null)
            TextButton.icon(
              icon: const Icon(Icons.mail_outline, size: 18),
              label: Text(email!),
              onPressed: () => launchUrl(Uri(scheme: 'mailto', path: email)),
            ),
        ],
      ),
    );
  }
}

/// Quién cancela y qué pasará (la vista previa del servidor), antes de confirmar.
/// Devuelve `negocio` o `cliente`, o null si se arrepiente.
class _DialogoCancelar extends StatefulWidget {
  const _DialogoCancelar({
    required this.sesion,
    required this.miembro,
    required this.efecto,
  });

  final SesionAgenda sesion;
  final String miembro;
  final Future<EfectoCancelacion> Function(String por) efecto;

  @override
  State<_DialogoCancelar> createState() => _DialogoCancelarState();
}

class _DialogoCancelarState extends State<_DialogoCancelar> {
  var _por = 'negocio';
  late Future<EfectoCancelacion> _efecto = widget.efecto(_por);

  void _elegir(String por) => setState(() {
    _por = por;
    _efecto = widget.efecto(por);
  });

  @override
  Widget build(BuildContext context) {
    final cliente = widget.sesion.cita?.cliente;
    final hora = _hhmm(widget.sesion.iniciaLocal);
    return AlertDialog(
      title: const Text('Cancelar cita'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            cliente == null
                ? '¿Cancelar esta cita de las $hora? El horario quedará libre.'
                : '¿Cancelar la cita de $cliente de las $hora? El horario '
                      'quedará libre.',
          ),
          const SizedBox(height: 12),
          SegmentedButton<String>(
            segments: [
              const ButtonSegment(
                value: 'negocio',
                label: Text('Cancela el negocio'),
              ),
              ButtonSegment(
                value: 'cliente',
                label: Text('Lo pidió el ${widget.miembro}'),
              ),
            ],
            selected: {_por},
            showSelectedIcon: false,
            onSelectionChanged: (v) => _elegir(v.first),
          ),
          const SizedBox(height: 12),
          FutureBuilder<EfectoCancelacion>(
            future: _efecto,
            builder: (context, foto) {
              if (foto.connectionState != ConnectionState.done) {
                return const LinearProgressIndicator();
              }
              final efecto = foto.data;
              return Text(
                foto.hasError || efecto == null
                    ? 'No se pudo saber qué pasará con su crédito o su pago.'
                    : efecto.mensaje,
                key: const Key('efecto-cancelar'),
                style: TextStyle(color: context.colores.textoSuave),
              );
            },
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Volver'),
        ),
        FutureBuilder<EfectoCancelacion>(
          future: _efecto,
          builder: (context, foto) {
            // No se confirma con el efecto de la otra opción a la vista ni con
            // algo que el servidor no permitiría.
            final listo =
                foto.connectionState == ConnectionState.done &&
                (foto.data?.cancelable ?? !foto.hasError);
            return TextButton(
              style: TextButton.styleFrom(
                foregroundColor: context.colores.error,
              ),
              onPressed: listo ? () => Navigator.of(context).pop(_por) : null,
              child: const Text('Sí, cancelar'),
            );
          },
        ),
      ],
    );
  }
}

/// Lo que ha pasado con la cita y quién lo hizo; se carga al abrirlo.
class _HistorialCita extends ConsumerStatefulWidget {
  const _HistorialCita({required this.sesion, required this.moneda});

  final SesionAgenda sesion;
  final String moneda;

  @override
  ConsumerState<_HistorialCita> createState() => _HistorialCitaState();
}

class _HistorialCitaState extends ConsumerState<_HistorialCita> {
  Future<List<HechoCita>>? _hechos;

  @override
  Widget build(BuildContext context) {
    return ExpansionTile(
      key: const Key('historial-cita'),
      tilePadding: EdgeInsets.zero,
      title: const Text(
        'Historial',
        style: TextStyle(fontWeight: FontWeight.w700),
      ),
      onExpansionChanged: (abierto) {
        if (abierto && _hechos == null) {
          setState(
            () => _hechos = ref
                .read(agendaProvider.notifier)
                .historial(widget.sesion),
          );
        }
      },
      children: [
        FutureBuilder<List<HechoCita>>(
          future: _hechos,
          builder: (context, foto) {
            if (foto.connectionState != ConnectionState.done) {
              return const Padding(
                padding: EdgeInsets.all(12),
                child: Text('Cargando historial…'),
              );
            }
            if (foto.hasError) {
              return const Padding(
                padding: EdgeInsets.all(12),
                child: Text('No se pudo cargar el historial.'),
              );
            }
            final hechos = foto.data ?? const [];
            if (hechos.isEmpty) {
              return const Padding(
                padding: EdgeInsets.all(12),
                child: Text('Aún no hay movimientos.'),
              );
            }
            // Cuándo pasó, en la hora de la sucursal (como el resto de la hoja).
            String cuando(DateTime fecha) {
              final f = widget.sesion.enSucursal(fecha);
              return '${f.day}/${f.month} ${_hhmm(f)}';
            }

            return Column(
              children: [
                for (final h in hechos)
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text(h.titulo),
                    subtitle: Text(
                      [
                        h.detalleEn(widget.moneda, Formato.dinero),
                        '${cuando(h.fecha)}'
                            '${h.actor != null ? ' · por ${h.actor}' : ''}',
                      ].whereType<String>().join('\n'),
                    ),
                  ),
              ],
            );
          },
        ),
      ],
    );
  }
}
