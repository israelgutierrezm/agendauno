import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/hora_negocio.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/data/cuenta_models.dart' show HorarioCita;
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import '../data/agenda_repository.dart';
import 'horarios_cita.dart';

/// Abre «Reprogramar» de una cita (fase 2, punto 2.1): a otra hora y, si se
/// quiere, con otro profesional. Devuelve si se movió.
Future<bool> mostrarReprogramarCita(
  BuildContext context,
  SesionAgenda sesion,
  List<Profesional> profesionales,
) async {
  final movida = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) =>
        ReprogramarCitaSheet(sesion: sesion, profesionales: profesionales),
  );
  return movida ?? false;
}

/// Mover una cita como en la web: se conserva todo (cliente, pagos e historial) y
/// el servidor revalida que el nuevo horario esté libre; si no, no cambia nada y
/// dice por qué. Se confirma con el horario de antes y el nuevo a la vista.
class ReprogramarCitaSheet extends ConsumerStatefulWidget {
  const ReprogramarCitaSheet({
    super.key,
    required this.sesion,
    required this.profesionales,
  });

  final SesionAgenda sesion;
  final List<Profesional> profesionales;

  @override
  ConsumerState<ReprogramarCitaSheet> createState() =>
      _ReprogramarCitaSheetState();
}

class _ReprogramarCitaSheetState extends ConsumerState<ReprogramarCitaSheet> {
  String? _profesionalId;
  late DateTime _dia;
  List<HorarioCita> _horarios = const [];
  bool _buscando = false;
  String? _errorHoras;
  DateTime? _hora;
  int _pedido = 0;
  bool _guardando = false;
  String? _error;

  SesionAgenda get _s => widget.sesion;

  @override
  void initState() {
    super.initState();
    final actual = _s.instructorId;
    _profesionalId = widget.profesionales.any((p) => p.id == actual)
        ? actual
        : widget.profesionales.firstOrNull?.id;
    final inicio = _s.iniciaLocal;
    _dia = DateTime(inicio.year, inicio.month, inicio.day);
    _buscarHorarios();
  }

  Future<void> _buscarHorarios() async {
    final repo = ref.read(agendaRepositoryProvider);
    final profesional = _profesionalId;
    final sucursal = _s.sucursalId;
    setState(() {
      _horarios = const [];
      _hora = null;
      _errorHoras = null;
      _error = null;
    });
    // Sin su sucursal (un API anterior) no hay huecos que pedir: otra hora sí.
    if (repo == null || profesional == null || sucursal == null) {
      return;
    }
    final pedido = ++_pedido;
    setState(() => _buscando = true);
    try {
      final horarios = await repo.horariosLibres(
        instructorId: profesional,
        sucursalId: sucursal,
        fecha: _dia,
        ofertaId: _s.ofertaId,
        // La que ya tiene la cita (el servidor usa la del servicio si la fija).
        duracionMinutos: _s.duracionMin > 0 ? _s.duracionMin : null,
        // Su propio horario está libre para ella (moverla unos minutos).
        excluirReserva: _s.cita?.reservaId,
      );
      if (mounted && pedido == _pedido) {
        setState(() {
          _horarios = horarios;
          _buscando = false;
        });
      }
    } on DioException catch (e) {
      if (mounted && pedido == _pedido) {
        setState(() {
          _errorHoras = mensajeDeError(
            e,
            porDefecto: 'No se pudieron cargar los horarios.',
          );
          _buscando = false;
        });
      }
    }
  }

  /// Hoy en la sucursal de la cita.
  DateTime get _hoy => _s.hoyEnSucursal(DateTime.now());

  Future<void> _elegirDia() async {
    final dia = await elegirDiaCita(context, inicial: _dia, hoy: _hoy);
    if (dia != null && mounted) {
      setState(() => _dia = dia);
      await _buscarHorarios();
    }
  }

  Future<void> _otraHora() async {
    final actual = _s.iniciaLocal;
    final hora = await elegirOtraHora(
      context,
      dia: _dia,
      // Se parte de la hora que ya tiene (en el día elegido).
      inicial: _hora ?? actual,
    );
    if (hora != null && mounted) {
      setState(() {
        _hora = hora;
        _error = null;
      });
    }
  }

  Future<void> _mover() async {
    final hora = _hora;
    final profesional = _profesionalId;
    if (hora == null) {
      return;
    }
    final cliente = _s.cita?.cliente ?? 'el cliente';
    final antes =
        '${Formato.diaHora(_s.iniciaLocal)} con ${_s.instructor ?? '—'}';
    final ahora =
        '${Formato.diaHora(hora)} con '
        '${profesional == null ? (_s.instructor ?? '—') : nombreProfesional(widget.profesionales, profesional)}';
    final si = await confirmarCita(
      context,
      titulo: 'Reprogramar cita',
      mensaje: '¿Mover la cita de $cliente?',
      filas: [('Antes', antes), ('Ahora', ahora)],
      aceptar: 'Mover',
    );
    if (!si || !mounted) {
      return;
    }
    setState(() {
      _guardando = true;
      _error = null;
    });
    final navegador = Navigator.of(context);
    final mensajero = ScaffoldMessenger.of(context);
    try {
      await ref
          .read(agendaProvider.notifier)
          .reprogramar(_s, inicia: hora, instructorId: profesional);
      // Las mismas palabras que la web («Movida: antes …, ahora …»).
      mensajero.showSnackBar(
        SnackBar(
          content: Text(
            'Movida: antes ${Formato.diaHora(_s.iniciaLocal)}, ahora '
            '${Formato.diaHora(hora)}.',
          ),
        ),
      );
      if (mounted) {
        navegador.pop(true);
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(
          () => _error = mensajeDeError(
            e,
            porDefecto: 'No se pudo cambiar el horario.',
          ),
        );
        // El hueco pudo ocuparse: se vuelven a pedir los libres (y se dice por qué).
        final motivo = _error;
        await _buscarHorarios();
        if (mounted) {
          setState(() => _error = motivo);
        }
      }
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final terminos = ref.watch(sesionProvider)?.terminologia;
    final suave = TextStyle(color: context.colores.textoSuave);
    return Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        0,
        20,
        20 + MediaQuery.of(context).viewInsets.bottom,
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Reprogramar cita',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            Text(
              [_s.cita?.cliente, _s.oferta].whereType<String>().join(' · '),
              style: suave,
            ),
            const SizedBox(height: 4),
            Text(
              'Ahora: ${Formato.diaHora(_s.iniciaLocal)} con '
              '${_s.instructor ?? '—'}',
              key: const Key('horario-actual'),
              style: suave,
            ),
            const SizedBox(height: 16),
            if (widget.profesionales.length > 1)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: DropdownButtonFormField<String>(
                  key: const Key('profesional-reprogramar'),
                  initialValue: _profesionalId,
                  isExpanded: true,
                  decoration: InputDecoration(
                    labelText: terminos?.instructor ?? 'Con',
                  ),
                  items: [
                    for (final p in widget.profesionales)
                      DropdownMenuItem(value: p.id, child: Text(p.nombre)),
                  ],
                  onChanged: _guardando
                      ? null
                      : (id) {
                          setState(() => _profesionalId = id);
                          _buscarHorarios();
                        },
                ),
              ),
            BotonDiaCita(dia: _dia, alTocar: _guardando ? null : _elegirDia),
            const SizedBox(height: 12),
            HorariosCita(
              cargando: _buscando,
              error: _errorHoras,
              horarios: _horarios,
              elegida: _hora,
              alElegir: (h) => setState(() {
                _hora = h;
                _error = null;
              }),
              alOtraHora: _guardando ? null : _otraHora,
              alReintentar: _buscarHorarios,
              vacio: _s.sucursalId == null
                  ? 'Elige la nueva hora.'
                  : 'No hay horarios libres ese día con '
                        '${nombreProfesional(widget.profesionales, _profesionalId)}. '
                        'Prueba otro día, otra persona u otra hora.',
              etiquetaZona: _horarios.any((h) => h.enOtraZona)
                  ? HoraNegocio.etiqueta([_s.sucursal])
                  : null,
            ),
            const SizedBox(height: 12),
            Text(
              'Se conserva todo: cliente, pagos e historial. Si el nuevo horario '
              'ya no está libre, no cambia nada.',
              style: suave,
            ),
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(
                _error!,
                key: const Key('error-reprogramar'),
                style: TextStyle(color: context.colores.error),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton(
              key: const Key('reprogramar-cita'),
              onPressed: _hora != null && !_guardando ? _mover : null,
              child: Text(_guardando ? 'Cambiando…' : 'Cambiar'),
            ),
          ],
        ),
      ),
    );
  }
}
