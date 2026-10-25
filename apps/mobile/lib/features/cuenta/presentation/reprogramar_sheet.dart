import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/hora_negocio.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'agendar_cita_sheet.dart' show textoHorasDeLaSede;
import 'cuenta_screen.dart';

/// Cambiar el horario de una reserva desde la cuenta (ADR 0044): una cita a otro
/// horario libre del mismo servicio y profesional, o una clase a otra fecha con
/// lugar. Hasta cuándo y cuántas veces lo decide el negocio; si ya no se puede,
/// dice por qué.
class ReprogramarSheet extends ConsumerStatefulWidget {
  const ReprogramarSheet(this.reserva, {super.key});

  final ReservaMiembro reserva;

  @override
  ConsumerState<ReprogramarSheet> createState() => _ReprogramarSheetState();
}

class _ReprogramarSheetState extends ConsumerState<ReprogramarSheet> {
  OpcionesReprogramar? _opciones;
  late DateTime _dia;
  // Cita: el `inicia` del horario elegido; clase: el id de la sesión.
  String? _elegido;
  bool _cargando = true;
  bool _guardando = false;
  // Por qué no se pudieron cargar las opciones (sin red o una respuesta que esta
  // versión no entiende).
  String? _error;

  /// Desfase con UTC (minutos) de la sede de la reserva o, si el API no lo manda,
  /// del negocio: los días y las horas son los de allá, no los del teléfono. Con
  /// el del negocio, una cita a las 00:30 de una sede en otra zona abría los
  /// horarios del día anterior.
  int? get _desfase =>
      widget.reserva.desfaseMin ?? ref.read(sesionProvider)?.desfaseNegocioMin;

  /// Lo que se cambia: una cita o una reserva de clase.
  String get _que => widget.reserva.esCita ? 'cita' : 'reserva';

  /// Hoy en el negocio.
  DateTime get _hoy {
    final ahora = HoraNegocio.enDesfase(DateTime.now(), _desfase);
    return DateTime(ahora.year, ahora.month, ahora.day);
  }

  @override
  void initState() {
    super.initState();
    // El día de la reserva en su sede (el API no manda su hora local).
    final inicio = HoraNegocio.pared(
      widget.reserva.iniciaEn,
      desfaseMin: _desfase,
    );
    _dia = inicio == null
        ? _hoy
        : DateTime(inicio.year, inicio.month, inicio.day);
    _cargar();
  }

  Future<void> _cargar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    setState(() {
      _cargando = true;
      _elegido = null;
      _error = null;
    });
    try {
      final opciones = await repo.opcionesReprogramar(
        widget.reserva.id,
        fecha: Formato.iso(_dia),
      );
      if (mounted) {
        setState(() {
          _opciones = opciones;
          _cargando = false;
        });
      }
    } on TipoSesionDesconocido catch (e) {
      _fallo('$e');
    } on DioException {
      _fallo('No se pudieron cargar los horarios. Intenta de nuevo.');
    }
  }

  void _fallo(String mensaje) {
    if (mounted) {
      setState(() {
        _cargando = false;
        _error = mensaje;
      });
    }
  }

  Future<void> _elegirDia() async {
    final hoy = _hoy;
    final dia = await showDatePicker(
      context: context,
      initialDate: _dia.isBefore(hoy) ? hoy : _dia,
      currentDate: hoy,
      firstDate: hoy,
      lastDate: hoy.add(const Duration(days: 90)),
    );
    if (dia != null) {
      _dia = dia;
      await _cargar();
    }
  }

  Future<void> _cambiar() async {
    final opciones = _opciones;
    final elegido = _elegido;
    if (opciones == null || elegido == null) {
      return;
    }
    setState(() => _guardando = true);
    final navegador = Navigator.of(context);
    final notifier = ref.read(cuentaProvider.notifier);
    await hacerConAviso(context, () async {
      switch (opciones.tipo) {
        case TipoSesion.cita:
          // La hora de la sede tal cual (no la del teléfono).
          final horario = opciones.horarios.firstWhere(
            (h) => h.inicia == elegido,
          );
          await notifier.reprogramar(
            widget.reserva.id,
            iniciaEnLocal: horario.iniciaEnLocal,
          );
        case TipoSesion.clase:
          await notifier.reprogramar(widget.reserva.id, sesionId: elegido);
      }
      navegador.pop();
    }, exito: 'Listo: cambiamos tu horario.');
    if (mounted) {
      setState(() => _guardando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final opciones = _opciones;
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Cambiar horario',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            Text(
              widget.reserva.oferta ?? '',
              style: TextStyle(color: context.colores.textoSuave),
            ),
            const SizedBox(height: 16),
            if (_error != null && opciones == null)
              Text(_error!, style: TextStyle(color: context.colores.error))
            else if (opciones == null)
              const SizedBox(
                height: 120,
                child: Center(child: CircularProgressIndicator()),
              )
            else if (!opciones.puede)
              Text(opciones.motivo ?? 'Esta $_que ya no se puede cambiar.')
            else ...[
              Text(
                opciones.restantes == 1
                    ? 'Te queda 1 cambio para esta $_que.'
                    : 'Te quedan ${opciones.restantes} cambios para esta $_que.',
                style: TextStyle(color: context.colores.textoSuave),
              ),
              const SizedBox(height: 12),
              ...switch (opciones.tipo) {
                TipoSesion.cita => _cita(opciones),
                TipoSesion.clase => [_clase(opciones)],
              },
              const SizedBox(height: 20),
              FilledButton(
                onPressed: _elegido == null || _guardando ? null : _cambiar,
                child: const Text('Cambiar'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  List<Widget> _cita(OpcionesReprogramar opciones) => [
    OutlinedButton.icon(
      icon: const Icon(Icons.calendar_today_outlined, size: 18),
      label: Text(Formato.dia(_dia)),
      onPressed: _cargando ? null : _elegirDia,
    ),
    const SizedBox(height: 16),
    if (_cargando)
      const Center(child: CircularProgressIndicator())
    else if (opciones.horarios.isEmpty)
      Text(
        'No hay horarios libres ese día. Prueba otro.',
        style: TextStyle(color: context.colores.textoSuave),
      )
    else
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: opciones.horarios
            .map(
              (h) => ChoiceChip(
                label: Text(h.hora),
                selected: _elegido == h.inicia,
                onSelected: (_) => setState(() => _elegido = h.inicia),
              ),
            )
            .toList(),
      ),
    if (!_cargando && opciones.horarios.any((h) => h.enOtraZona)) ...[
      const SizedBox(height: 8),
      Text(
        textoHorasDeLaSede,
        key: Key('horas-de-la-sede'),
        style: TextStyle(color: context.colores.textoSuave),
      ),
    ],
  ];

  Widget _clase(OpcionesReprogramar opciones) => opciones.sesiones.isEmpty
      ? Text(
          'No hay otras fechas con lugar por ahora.',
          style: TextStyle(color: context.colores.textoSuave),
        )
      : RadioGroup<String>(
          groupValue: _elegido,
          onChanged: (v) => setState(() => _elegido = v),
          child: Column(
            children: opciones.sesiones
                .map(
                  (s) => RadioListTile<String>(
                    contentPadding: EdgeInsets.zero,
                    value: s.id,
                    // La hora de la clase en su sede; sin ella, la del negocio.
                    title: Text(
                      Formato.fechaHora(
                        s.iniciaLocal ?? s.iniciaEn,
                        desfaseMin: _desfase,
                      ),
                    ),
                  ),
                )
                .toList(),
          ),
        );
}
