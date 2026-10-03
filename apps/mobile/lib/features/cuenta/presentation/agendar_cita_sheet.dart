import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_screen.dart';
import 'elegir_profesional.dart';

/// Agendar una cita desde la cuenta: servicio, sede, de quién ver horarios (todo el
/// equipo o alguien, por su foto, como en la web), día y hora libre. Si el servicio
/// se paga para reservar, la cita queda apartada hasta pagarla. Los servicios de
/// su bono o membresía aparecen «Con tu bono» y no se pagan (ADR 0091).
class AgendarCitaSheet extends ConsumerStatefulWidget {
  const AgendarCitaSheet({super.key});

  @override
  ConsumerState<AgendarCitaSheet> createState() => _AgendarCitaSheetState();
}

/// Sin preferencia: el negocio asigna a quien esté libre a esa hora.
const _cualquiera = OpcionCita(
  id: '',
  nombre: 'Cualquier profesional disponible',
);

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
  // Avisos por WhatsApp (ADR 0069): se ofrecen si el negocio los usa, aún no los
  // aceptó y tiene celular.
  bool _ofrecerWhatsapp = false;
  bool _aceptaWhatsapp = false;
  // Nota para el negocio (opcional): alergias, preferencias, primera vez…
  final _nota = TextEditingController();

  @override
  void initState() {
    super.initState();
    _cargarOpciones();
  }

  @override
  void dispose() {
    _nota.dispose();
    super.dispose();
  }

  Future<void> _cargarOpciones() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final opciones = await repo.opcionesCita();
    var ofrecerWhatsapp = false;
    try {
      ofrecerWhatsapp = (await repo.privacidad()).ofrecerWhatsapp;
    } on DioException {
      ofrecerWhatsapp = false;
    }
    if (!mounted) {
      return;
    }
    setState(() {
      _opciones = opciones;
      _sede = opciones.sucursales.length == 1
          ? opciones.sucursales.first
          : null;
      // Con varios, se parte de todo el equipo; con uno, es esa persona.
      _profesional = opciones.profesionales.length > 1
          ? _cualquiera
          : opciones.profesionales.firstOrNull;
      _ofrecerWhatsapp = ofrecerWhatsapp;
      _cargando = false;
    });
  }

  String? get _idProfesional =>
      identical(_profesional, _cualquiera) ? null : _profesional?.id;

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
      profesionalId: _idProfesional,
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
    final mensajero = ScaffoldMessenger.of(context);
    await hacerConAviso(context, () async {
      // El API recibe la hora local de la sede; el dispositivo está en la misma zona.
      final inicio = DateTime.parse(hora).toLocal();
      final cita = await repo.agendarCita(
        servicioId: _servicio!.id,
        sucursalId: _sede!.id,
        profesionalId: _idProfesional,
        iniciaEnLocal: '${Formato.iso(inicio)}T${Formato.hora(inicio)}',
        duracionMinutos: _servicio!.duracionMinutos ?? 60,
        nota: _nota.text.trim().isEmpty ? null : _nota.text.trim(),
        aceptaWhatsapp: _ofrecerWhatsapp && _aceptaWhatsapp,
      );
      await ref.read(cuentaProvider.notifier).recargar();
      navegador.pop();
      // Un solo aviso: si quedó apartada o agendada y, con «cualquiera», con quién.
      final quien = cita.profesional;
      final conQuien = identical(_profesional, _cualquiera) && quien != null
          ? ' Te atenderá $quien.'
          : '';
      final aviso = cita.estado == 'pendiente_pago'
          ? 'Tu cita quedó apartada. Págala para confirmarla.'
          : _servicio!.conPlan
          ? '¡Listo! Agendamos tu cita con tu bono.'
          : '¡Listo! Agendamos tu cita.';
      mensajero.showSnackBar(SnackBar(content: Text('$aviso$conQuien')));
    });
    if (mounted) {
      setState(() => _agendando = false);
    }
  }

  /// Días hacia adelante que ofrece el calendario (lo que el API da de una vez).
  static const _diasCalendario = 62;

  Future<void> _elegirDia() async {
    final ahora = DateTime.now();
    final hoy = DateTime(ahora.year, ahora.month, ahora.day);
    final ultimo = hoy.add(const Duration(days: _diasCalendario - 1));
    // Solo los días en que alguien atiende (ADR 0065); si no se pueden saber (sin
    // sede o sin red), cualquiera: el horario lo vuelve a revisar.
    Set<String>? abiertos;
    final repo = ref.read(cuentaRepositoryProvider);
    final sede = _sede;
    if (repo != null && sede != null) {
      try {
        abiertos = await repo.diasConAtencion(
          sucursalId: sede.id,
          desde: Formato.iso(hoy),
          dias: _diasCalendario,
          profesionalId: _idProfesional,
        );
      } on DioException {
        abiertos = null;
      }
    }
    if (!mounted) {
      return;
    }
    bool sePuede(DateTime d) =>
        abiertos == null || abiertos.contains(Formato.iso(d));
    DateTime? inicial = _dia != null && sePuede(_dia!) ? _dia : null;
    for (var i = 0; inicial == null && i < _diasCalendario; i++) {
      final d = hoy.add(Duration(days: i));
      if (sePuede(d)) {
        inicial = d;
      }
    }
    if (inicial == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('No hay días con atención en los próximos dos meses.'),
        ),
      );
      return;
    }
    final dia = await showDatePicker(
      context: context,
      initialDate: inicial,
      firstDate: hoy,
      lastDate: ultimo,
      selectableDayPredicate: sePuede,
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
                  if (_servicio?.incluye.isNotEmpty ?? false)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Text(
                        'Incluye: ${_servicio!.incluye.join(' · ')}',
                        style: const TextStyle(color: TemaAgendaUno.textoSuave),
                      ),
                    ),
                  if (_servicio?.conPlan ?? false)
                    const Padding(
                      key: Key('pago-con-bono'),
                      padding: EdgeInsets.only(bottom: 12),
                      child: Text(
                        'Se descuenta una sesión de tu bono o membresía; no pagas al agendar.',
                        style: TextStyle(color: TemaAgendaUno.textoSuave),
                      ),
                    ),
                  if (opciones.sucursales.length > 1)
                    _selector('Sede', opciones.sucursales, _sede, (v) {
                      setState(() => _sede = v);
                      _buscarHorarios();
                    }),
                  if (opciones.profesionales.length > 1) ...[
                    ElegirProfesional(
                      profesionales: opciones.profesionales,
                      seleccionado: _idProfesional,
                      alCambiar: (id) {
                        setState(
                          () => _profesional = id == null
                              ? _cualquiera
                              : opciones.profesionales.firstWhere(
                                  (p) => p.id == id,
                                ),
                        );
                        _buscarHorarios();
                      },
                    ),
                    const SizedBox(height: 12),
                  ],
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
                    // Horas en cuadrícula pareja, como en la web.
                    LayoutBuilder(
                      builder: (context, caja) {
                        const separacion = 8.0;
                        final columnas = (caja.maxWidth / 88).floor().clamp(
                          3,
                          6,
                        );
                        final ancho =
                            (caja.maxWidth - separacion * (columnas - 1)) /
                            columnas;
                        return Wrap(
                          spacing: separacion,
                          runSpacing: separacion,
                          children: _horarios.map((h) {
                            final elegida = _hora == h;
                            final texto = Formato.hora(
                              DateTime.parse(h).toLocal(),
                            );
                            return SizedBox(
                              width: ancho,
                              height: 44,
                              child: elegida
                                  ? FilledButton(
                                      onPressed: () =>
                                          setState(() => _hora = h),
                                      child: Text(texto),
                                    )
                                  : OutlinedButton(
                                      onPressed: () =>
                                          setState(() => _hora = h),
                                      child: Text(texto),
                                    ),
                            );
                          }).toList(),
                        );
                      },
                    ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _nota,
                    maxLength: 500,
                    maxLines: 2,
                    decoration: const InputDecoration(
                      labelText: 'Nota para el negocio (opcional)',
                      hintText: 'Alergias, preferencias, si es tu primera vez…',
                    ),
                  ),
                  if (_ofrecerWhatsapp)
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      value: _aceptaWhatsapp,
                      onChanged: (v) =>
                          setState(() => _aceptaWhatsapp = v ?? false),
                      title: const Text(
                        'Quiero recibir la confirmación y los recordatorios de mi cita por WhatsApp',
                      ),
                    ),
                  const SizedBox(height: 12),
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
          .map(
            (o) => DropdownMenuItem(
              value: o,
              child: Text(o.conPlan ? '${o.nombre} · Con tu bono' : o.nombre),
            ),
          )
          .toList(),
      onChanged: alCambiar,
    ),
  );
}
