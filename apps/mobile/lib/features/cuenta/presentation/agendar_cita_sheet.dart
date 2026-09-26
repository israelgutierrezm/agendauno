import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_screen.dart';

/// Agendar una cita desde la cuenta: servicio, profesional, sede, día y hora libre.
/// Si el servicio se paga para reservar, la cita queda apartada hasta pagarla.
class AgendarCitaSheet extends ConsumerStatefulWidget {
  const AgendarCitaSheet({super.key});

  @override
  ConsumerState<AgendarCitaSheet> createState() => _AgendarCitaSheetState();
}

class _AgendarCitaSheetState extends ConsumerState<AgendarCitaSheet> {
  OpcionesCita? _opciones;
  OpcionCita? _servicio;
  OpcionCita? _profesional;
  OpcionCita? _sede;
  DateTime? _dia;
  List<String> _horarios = [];
  String? _hora;
  bool _cargando = true;
  bool _buscando = false;
  bool _agendando = false;

  @override
  void initState() {
    super.initState();
    _cargarOpciones();
  }

  Future<void> _cargarOpciones() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final opciones = await repo.opcionesCita();
    if (!mounted) {
      return;
    }
    setState(() {
      _opciones = opciones;
      _sede = opciones.sucursales.length == 1
          ? opciones.sucursales.first
          : null;
      _cargando = false;
    });
  }

  bool get _listo =>
      _servicio != null &&
      _profesional != null &&
      _sede != null &&
      _dia != null;

  Future<void> _buscarHorarios() async {
    setState(() {
      _horarios = [];
      _hora = null;
    });
    final repo = ref.read(cuentaRepositoryProvider);
    if (!_listo || repo == null) {
      return;
    }
    setState(() => _buscando = true);
    final horarios = await repo.horariosLibres(
      profesionalId: _profesional!.id,
      sucursalId: _sede!.id,
      fecha: Formato.iso(_dia!),
      duracionMinutos: _servicio!.duracionMinutos ?? 60,
      servicioId: _servicio!.id,
    );
    if (mounted) {
      setState(() {
        _horarios = horarios;
        _buscando = false;
      });
    }
  }

  Future<void> _agendar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    final hora = _hora;
    if (repo == null || hora == null || !_listo) {
      return;
    }
    setState(() => _agendando = true);
    final navegador = Navigator.of(context);
    await hacerConAviso(context, () async {
      // El API recibe la hora local de la sede; el dispositivo está en la misma zona.
      final inicio = DateTime.parse(hora).toLocal();
      final estado = await repo.agendarCita(
        servicioId: _servicio!.id,
        sucursalId: _sede!.id,
        profesionalId: _profesional!.id,
        iniciaEnLocal: '${Formato.iso(inicio)}T${Formato.hora(inicio)}',
        duracionMinutos: _servicio!.duracionMinutos ?? 60,
      );
      await ref.read(cuentaProvider.notifier).recargar();
      navegador.pop();
      if (estado == 'pendiente_pago' && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Tu cita quedó apartada. Págala para confirmarla.'),
          ),
        );
      }
    }, exito: '¡Listo! Agendamos tu cita.');
    if (mounted) {
      setState(() => _agendando = false);
    }
  }

  Future<void> _elegirDia() async {
    final hoy = DateTime.now();
    final dia = await showDatePicker(
      context: context,
      initialDate: _dia ?? hoy,
      firstDate: hoy,
      lastDate: hoy.add(const Duration(days: 90)),
    );
    if (dia != null) {
      setState(() => _dia = dia);
      await _buscarHorarios();
    }
  }

  @override
  Widget build(BuildContext context) {
    final opciones = _opciones;
    return Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        0,
        20,
        20 + MediaQuery.of(context).viewInsets.bottom,
      ),
      child: _cargando || opciones == null
          ? const SizedBox(
              height: 160,
              child: Center(child: CircularProgressIndicator()),
            )
          : opciones.servicios.isEmpty || opciones.profesionales.isEmpty
          ? const Padding(
              padding: EdgeInsets.symmetric(vertical: 24),
              child: Text(
                'Este negocio aún no tiene servicios para agendar en línea.',
              ),
            )
          : SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    'Agendar una cita',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 16),
                  _selector('Servicio', opciones.servicios, _servicio, (v) {
                    setState(() => _servicio = v);
                    _buscarHorarios();
                  }),
                  _selector(
                    'Profesional',
                    opciones.profesionales,
                    _profesional,
                    (v) {
                      setState(() => _profesional = v);
                      _buscarHorarios();
                    },
                  ),
                  if (opciones.sucursales.length > 1)
                    _selector('Sede', opciones.sucursales, _sede, (v) {
                      setState(() => _sede = v);
                      _buscarHorarios();
                    }),
                  OutlinedButton.icon(
                    icon: const Icon(Icons.calendar_today_outlined, size: 18),
                    label: Text(
                      _dia == null ? 'Elegir día' : Formato.dia(_dia!),
                    ),
                    onPressed: _elegirDia,
                  ),
                  const SizedBox(height: 16),
                  if (_buscando)
                    const Center(child: CircularProgressIndicator())
                  else if (_listo && _horarios.isEmpty)
                    const Text(
                      'No hay horarios libres ese día. Prueba otro día u otro profesional.',
                      style: TextStyle(color: TemaAgendaUno.textoSuave),
                    )
                  else
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: _horarios
                          .map(
                            (h) => ChoiceChip(
                              label: Text(
                                Formato.hora(DateTime.parse(h).toLocal()),
                              ),
                              selected: _hora == h,
                              onSelected: (_) => setState(() => _hora = h),
                            ),
                          )
                          .toList(),
                    ),
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: _hora == null || _agendando ? null : _agendar,
                    child: const Text('Agendar'),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _selector(
    String etiqueta,
    List<OpcionCita> lista,
    OpcionCita? valor,
    ValueChanged<OpcionCita?> alCambiar,
  ) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: DropdownButtonFormField<OpcionCita>(
      initialValue: valor,
      decoration: InputDecoration(labelText: etiqueta),
      items: lista
          .map((o) => DropdownMenuItem(value: o, child: Text(o.nombre)))
          .toList(),
      onChanged: alCambiar,
    ),
  );
}
