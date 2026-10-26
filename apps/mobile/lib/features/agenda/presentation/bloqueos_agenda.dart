import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import '../data/agenda_repository.dart';
import '../data/bloqueos.dart';

/// Los bloqueos que tocan el día de [profesionalId]: los suyos y los de las sedes
/// (un cierre vale para todos), en la hora del negocio.
List<BloqueoAgenda> bloqueosDe(
  List<BloqueoAgenda> bloqueos, {
  required String profesionalId,
  required DateTime dia,
  required DateTime Function(DateTime) pared,
}) => bloqueos
    .where(
      (b) =>
          (b.dePersona
              ? b.instructorId == profesionalId
              : b.ambito == 'sede') &&
          b.tocaElDia(dia, pared),
    )
    .toList(growable: false);

/// El horario de un bloqueo en palabras: «14:00–15:00», «Todo el día» o «Del 3 al
/// 5 de nov. · todo el día».
String horarioBloqueo(BloqueoAgenda b, DateTime Function(DateTime) pared) {
  final desde = pared(b.desde);
  final hasta = pared(b.hasta);
  if (b.todoElDia) {
    final ultimo = hasta.subtract(const Duration(days: 1));
    final mismo =
        ultimo.year == desde.year &&
        ultimo.month == desde.month &&
        ultimo.day == desde.day;
    return mismo
        ? 'Todo el día'
        : 'Del ${_diaCorto(desde)} al ${_diaCorto(ultimo)} · todo el día';
  }
  return '${Formato.hora(desde)}–${Formato.hora(hasta)}';
}

const _mesesCortos = [
  'ene.',
  'feb.',
  'mar.',
  'abr.',
  'may.',
  'jun.',
  'jul.',
  'ago.',
  'sep.',
  'oct.',
  'nov.',
  'dic.',
];

String _diaCorto(DateTime d) => '${d.day} de ${_mesesCortos[d.month - 1]}';

/// La hora de pared del negocio de un instante (sin sesión, la del teléfono).
DateTime Function(DateTime) paredDelNegocio(Sesion? sesion) =>
    (instante) => sesion?.enNegocio(instante) ?? instante.toLocal();

/// Bloquear un rato o días del profesional (comida, vacaciones, ausencia), con su
/// permiso (`agenda.gestionar`; quien imparte, solo el suyo: lo exige el servidor).
/// Antes de guardar se dice qué citas ya agendadas caen ahí (no se cancelan).
Future<void> mostrarNuevoBloqueo(
  BuildContext context, {
  required Profesional profesional,
  required DateTime dia,
  bool propio = false,
}) async {
  final mensajero = ScaffoldMessenger.of(context);
  final creado = await showModalBottomSheet<int>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) =>
        NuevoBloqueoSheet(profesional: profesional, dia: dia, propio: propio),
  );
  if (creado == null) {
    return;
  }
  mensajero.showSnackBar(
    SnackBar(
      content: Text(
        creado == 0
            ? 'Horario bloqueado.'
            : creado == 1
            ? 'Horario bloqueado. Queda 1 cita agendada ahí: atiéndela.'
            : 'Horario bloqueado. Quedan $creado citas agendadas ahí: atiéndelas.',
      ),
    ),
  );
}

class NuevoBloqueoSheet extends ConsumerStatefulWidget {
  const NuevoBloqueoSheet({
    super.key,
    required this.profesional,
    required this.dia,
    this.propio = false,
  });

  final Profesional profesional;
  final DateTime dia;

  /// Es el de quien lo pone (quien imparte, su propio horario).
  final bool propio;

  @override
  ConsumerState<NuevoBloqueoSheet> createState() => _NuevoBloqueoSheetState();
}

class _NuevoBloqueoSheetState extends ConsumerState<NuevoBloqueoSheet> {
  static const _motivos = ['Comida', 'Descanso', 'Vacaciones', 'Ausencia'];

  final _motivo = TextEditingController();
  var _todoElDia = false;
  var _desde = 14 * 60;
  var _hasta = 15 * 60;
  late DateTime _hastaDia = widget.dia;
  var _guardando = false;
  String? _error;

  @override
  void dispose() {
    _motivo.dispose();
    super.dispose();
  }

  NuevoBloqueo get _pedido => NuevoBloqueo(
    instructorId: widget.profesional.id,
    dia: widget.dia,
    motivo: _motivo.text,
    desdeMinuto: _todoElDia ? null : _desde,
    hastaMinuto: _todoElDia ? null : _hasta,
    hastaDia: _todoElDia ? _hastaDia : null,
  );

  String? get _invalido {
    if (_motivo.text.trim().isEmpty) {
      return 'Escribe el motivo.';
    }
    if (!_todoElDia && _hasta <= _desde) {
      return 'La hora final debe ser después de la inicial.';
    }
    return null;
  }

  static String _hhmm(int minuto) =>
      '${(minuto ~/ 60).toString().padLeft(2, '0')}:${(minuto % 60).toString().padLeft(2, '0')}';

  Future<void> _elegirHora({required bool inicio}) async {
    final actual = inicio ? _desde : _hasta;
    final hora = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: actual ~/ 60, minute: actual % 60),
      helpText: inicio ? 'Desde' : 'Hasta',
    );
    if (hora != null && mounted) {
      setState(() {
        final m = hora.hour * 60 + hora.minute;
        if (inicio) {
          _desde = m;
        } else {
          _hasta = m;
        }
      });
    }
  }

  Future<void> _elegirHastaDia() async {
    final dia = await showDatePicker(
      context: context,
      initialDate: _hastaDia,
      firstDate: widget.dia,
      lastDate: widget.dia.add(const Duration(days: 365)),
      helpText: 'Hasta el día',
    );
    if (dia != null && mounted) {
      setState(() => _hastaDia = DateTime(dia.year, dia.month, dia.day));
    }
  }

  Future<void> _guardar() async {
    final repo = ref.read(agendaRepositoryProvider);
    final invalido = _invalido;
    if (repo == null || _guardando) {
      return;
    }
    if (invalido != null) {
      setState(() => _error = invalido);
      return;
    }
    setState(() {
      _guardando = true;
      _error = null;
    });
    final pedido = _pedido;
    try {
      // Antes de guardar: qué ya está agendado en ese horario (no se cancela).
      final afectadas = await repo.previsualizarBloqueo(pedido);
      if (!mounted) {
        return;
      }
      if (afectadas.isNotEmpty && !await _seguirConAfectadas(afectadas)) {
        setState(() => _guardando = false);
        return;
      }
      final creado = await repo.crearBloqueo(pedido);
      ref.invalidate(agendaProvider);
      if (mounted) {
        Navigator.of(context).pop(creado.afectadas.length);
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _error = mensajeDeError(e, porDefecto: 'No se pudo bloquear.');
          _guardando = false;
        });
      }
    }
  }

  Future<bool> _seguirConAfectadas(List<SesionAfectada> afectadas) async {
    final desfase = ref.read(sesionProvider)?.desfaseNegocioMin;
    final n = afectadas.length;
    return await showDialog<bool>(
          context: context,
          builder: (contexto) => AlertDialog(
            title: Text(
              n == 1
                  ? 'Hay 1 cita en ese horario'
                  : 'Hay $n citas en ese horario',
            ),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'El bloqueo no las cancela: siguen agendadas y hay que '
                  'moverlas o avisar.',
                ),
                const SizedBox(height: 8),
                for (final a in afectadas.take(5))
                  Text(
                    '• ${a.oferta ?? 'Cita'} · '
                    '${Formato.fechaHora(a.iniciaEn?.toUtc().toIso8601String(), desfaseMin: desfase)}',
                  ),
                if (n > 5) Text('y ${n - 5} más.'),
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(contexto).pop(false),
                child: const Text('Cancelar'),
              ),
              FilledButton(
                onPressed: () => Navigator.of(contexto).pop(true),
                child: const Text('Bloquear de todos modos'),
              ),
            ],
          ),
        ) ==
        true;
  }

  @override
  Widget build(BuildContext context) {
    final d = widget.dia;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          0,
          20,
          16 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                widget.propio
                    ? 'Bloquear tu horario'
                    : 'Bloquear el horario de ${widget.profesional.nombre}',
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'Nadie podrá agendar ahí. Lo que ya está agendado no se cancela.',
                style: TextStyle(color: context.colores.textoSuave),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('motivo-bloqueo'),
                controller: _motivo,
                enabled: !_guardando,
                maxLength: 255,
                textCapitalization: TextCapitalization.sentences,
                decoration: const InputDecoration(labelText: 'Motivo'),
                onChanged: (_) => setState(() => _error = null),
              ),
              Wrap(
                spacing: 6,
                children: [
                  for (final m in _motivos)
                    ActionChip(
                      label: Text(m),
                      onPressed: _guardando
                          ? null
                          : () => setState(() {
                              _motivo.text = m;
                              _error = null;
                            }),
                    ),
                ],
              ),
              SwitchListTile(
                key: const Key('todo-el-dia'),
                contentPadding: EdgeInsets.zero,
                title: const Text('Todo el día'),
                value: _todoElDia,
                onChanged: _guardando
                    ? null
                    : (v) => setState(() => _todoElDia = v),
              ),
              if (_todoElDia)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Hasta el día'),
                  subtitle: Text(
                    _hastaDia == d
                        ? 'Solo el ${_diaCorto(d)}'
                        : 'Del ${_diaCorto(d)} al ${_diaCorto(_hastaDia)}',
                  ),
                  trailing: const Icon(Icons.event_outlined),
                  onTap: _guardando ? null : _elegirHastaDia,
                )
              else
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        key: const Key('bloqueo-desde'),
                        onPressed: _guardando
                            ? null
                            : () => _elegirHora(inicio: true),
                        child: Text('Desde ${_hhmm(_desde)}'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton(
                        key: const Key('bloqueo-hasta'),
                        onPressed: _guardando
                            ? null
                            : () => _elegirHora(inicio: false),
                        child: Text('Hasta ${_hhmm(_hasta)}'),
                      ),
                    ),
                  ],
                ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    _error!,
                    style: TextStyle(color: context.colores.error),
                  ),
                ),
              const SizedBox(height: 16),
              FilledButton(
                key: const Key('guardar-bloqueo'),
                onPressed: _guardando ? null : _guardar,
                child: Text(_guardando ? 'Guardando…' : 'Bloquear'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// El detalle de un bloqueo: a quién, cuándo, por qué y quién lo puso; quitarlo con
/// su permiso (`agenda.eliminar`). Uno que viene del calendario de esa persona se
/// quita allá.
Future<void> mostrarBloqueo(BuildContext context, BloqueoAgenda b) =>
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (_) => _DetalleBloqueo(b),
    );

class _DetalleBloqueo extends ConsumerStatefulWidget {
  const _DetalleBloqueo(this.b);

  final BloqueoAgenda b;

  @override
  ConsumerState<_DetalleBloqueo> createState() => _DetalleBloqueoState();
}

class _DetalleBloqueoState extends ConsumerState<_DetalleBloqueo> {
  var _quitando = false;

  Future<void> _quitar() async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null || _quitando) {
      return;
    }
    final seguir = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        content: const Text(
          '¿Quitar el bloqueo? Ese horario se puede volver a agendar.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(contexto).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: contexto.colores.error,
            ),
            onPressed: () => Navigator.of(contexto).pop(true),
            child: const Text('Quitar bloqueo'),
          ),
        ],
      ),
    );
    if (seguir != true || !mounted) {
      return;
    }
    setState(() => _quitando = true);
    final nav = Navigator.of(context);
    final mensajero = ScaffoldMessenger.of(context);
    try {
      await repo.quitarBloqueo(widget.b.id);
      ref.invalidate(agendaProvider);
      nav.pop();
      mensajero.showSnackBar(const SnackBar(content: Text('Bloqueo quitado.')));
    } on DioException catch (e) {
      if (mounted) {
        setState(() => _quitando = false);
      }
      mensajero.showSnackBar(
        SnackBar(
          content: Text(mensajeDeError(e, porDefecto: 'No se pudo quitar.')),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = widget.b;
    final sesion = ref.watch(sesionProvider);
    final puedeQuitar = sesion?.puede('agenda.eliminar') ?? false;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              b.motivo ?? 'Bloqueado',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 4),
            Text(horarioBloqueo(b, paredDelNegocio(sesion))),
            if (b.nombre != null)
              Text(
                b.dePersona ? b.nombre! : 'Sede ${b.nombre}',
                style: TextStyle(color: context.colores.textoSuave),
              ),
            if (b.creadoPor != null)
              Text(
                'Lo puso ${b.creadoPor}',
                style: TextStyle(color: context.colores.textoSuave),
              ),
            const SizedBox(height: 16),
            if (b.deCalendario)
              Text(
                'Viene de ${b.origenTexto} de esa persona: se quita allá.',
                key: const Key('bloqueo-de-calendario'),
                style: TextStyle(color: context.colores.textoSuave),
              )
            else if (puedeQuitar)
              OutlinedButton(
                key: const Key('quitar-bloqueo'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: context.colores.error,
                ),
                onPressed: _quitando ? null : _quitar,
                child: Text(_quitando ? 'Quitando…' : 'Quitar bloqueo'),
              ),
          ],
        ),
      ),
    );
  }
}
