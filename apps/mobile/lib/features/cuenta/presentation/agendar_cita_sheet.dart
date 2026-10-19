import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_screen.dart';
import 'elegir_profesional.dart';

/// Agendar una cita desde la cuenta: servicio, sede, de quién ver horarios (todo el
/// equipo o alguien, por su foto, como en la web), día y hora libre. Si el servicio
/// se paga para reservar, la cita queda apartada hasta pagarla; si pide anticipo,
/// elige pagar solo el anticipo o todo (ADR 0161). Los servicios de su bono
/// aparecen «Con tu bono» y no se pagan (ADR 0091).
class AgendarCitaSheet extends ConsumerStatefulWidget {
  const AgendarCitaSheet({super.key});

  @override
  ConsumerState<AgendarCitaSheet> createState() => _AgendarCitaSheetState();
}

/// Se muestra junto a los horarios cuando la sede está en otra zona horaria que el
/// teléfono: las horas son las de la sede (las de la cita).
const textoHorasDeLaSede =
    'Las horas son las de la sede, que está en otra zona horaria que tu teléfono.';

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
  List<HorarioCita> _horarios = [];
  HorarioCita? _hora;
  bool _cargando = true;
  bool _buscando = false;
  // Si no cargan los servicios o los horarios, se dice (y se reintenta): nunca un
  // indicador girando para siempre.
  String? _errorCarga;
  String? _errorHorarios;
  bool _agendando = false;
  // Avisos por WhatsApp (ADR 0069): se ofrecen si el negocio los usa, aún no los
  // aceptó y tiene celular.
  bool _ofrecerWhatsapp = false;
  bool _aceptaWhatsapp = false;
  // Con anticipo (ADR 0161): paga solo el anticipo (por omisión) o todo.
  bool _pagarTodo = false;
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
    setState(() {
      _cargando = true;
      _errorCarga = null;
    });
    final OpcionesCita opciones;
    try {
      opciones = await repo.opcionesCita();
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _errorCarga = mensajeDeError(
            e,
            porDefecto: 'No se pudieron cargar los servicios.',
          );
          _cargando = false;
        });
      }
      return;
    }
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
      _profesional = null;
      _ajustarProfesional();
      _ofrecerWhatsapp = ofrecerWhatsapp;
      _cargando = false;
    });
  }

  /// Quienes atienden en la sede elegida (sin sede, todo el equipo).
  List<OpcionCita> get _profesionalesSede =>
      _opciones?.profesionalesEn(_sede?.id) ?? const [];

  /// Al elegir sede, si quien estaba elegido no atiende ahí, se parte de nuevo:
  /// con varios, todo el equipo de la sede; con uno, esa persona (como en la web).
  void _ajustarProfesional() {
    final lista = _profesionalesSede;
    final elegido = _profesional;
    if (elegido != null &&
        !identical(elegido, _cualquiera) &&
        lista.any((p) => p.id == elegido.id)) {
      return;
    }
    _profesional = lista.length > 1 ? _cualquiera : lista.firstOrNull;
  }

  String? get _idProfesional =>
      identical(_profesional, _cualquiera) ? null : _profesional?.id;

  /// El anticipo del servicio elegido (null: se paga completo o con su bono).
  int? get _anticipo => _servicio?.anticipoAlAgendar;

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
    setState(() {
      _buscando = true;
      _errorHorarios = null;
    });
    final List<HorarioCita> horarios;
    try {
      horarios = await repo.horariosLibres(
        profesionalId: _idProfesional,
        sucursalId: _sede!.id,
        fecha: Formato.iso(_dia!),
        duracionMinutos: _servicio!.duracionMinutos ?? 60,
        servicioId: _servicio!.id,
      );
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _errorHorarios = mensajeDeError(
            e,
            porDefecto: 'No se pudieron cargar los horarios.',
          );
          _buscando = false;
        });
      }
      return;
    }
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
    // Solo un servicio con anticipo pregunta cuánto pagar ahora.
    final conAnticipo = _anticipo != null;
    final pagar = !conAnticipo
        ? null
        : _pagarTodo
        ? 'todo'
        : 'anticipo';
    await hacerConAviso(context, () async {
      final cita = await repo.agendarCita(
        servicioId: _servicio!.id,
        sucursalId: _sede!.id,
        profesionalId: _idProfesional,
        // La hora de la sede tal cual (no la del teléfono).
        iniciaEnLocal: hora.iniciaEnLocal,
        duracionMinutos: _servicio!.duracionMinutos ?? 60,
        nota: _nota.text.trim().isEmpty ? null : _nota.text.trim(),
        aceptaWhatsapp: _ofrecerWhatsapp && _aceptaWhatsapp,
        pagar: pagar,
      );
      await ref.read(cuentaProvider.notifier).recargar();
      navegador.pop();
      // Un solo aviso: si quedó apartada o agendada y, con «cualquiera», con quién.
      final quien = cita.profesional;
      final conQuien = identical(_profesional, _cualquiera) && quien != null
          ? ' Te atenderá $quien.'
          : '';
      final aviso = cita.estado == 'pendiente_pago'
          ? pagar == 'anticipo'
                ? 'Tu cita quedó apartada. Paga el anticipo para confirmarla; '
                      'el resto lo pagas en el negocio.'
                : 'Tu cita quedó apartada. Págala para confirmarla.'
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
    // Hoy en el negocio, no en el teléfono: los días que se ofrecen son los de la
    // sede (el API no manda el desfase de cada una; vale el del negocio).
    final ahora = DateTime.now();
    final hoy =
        ref.read(sesionProvider)?.hoyEnNegocio(ahora) ??
        DateTime(ahora.year, ahora.month, ahora.day);
    // Días de calendario (sumar 24 h falla al cambiar el horario de verano).
    final ultimo = DateTime(hoy.year, hoy.month, hoy.day + _diasCalendario - 1);
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
      final d = DateTime(hoy.year, hoy.month, hoy.day + i);
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
      currentDate: hoy,
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
      child: _errorCarga != null
          ? Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(_errorCarga!),
                  const SizedBox(height: 12),
                  OutlinedButton(
                    onPressed: _cargarOpciones,
                    child: const Text('Reintentar'),
                  ),
                ],
              ),
            )
          : _cargando || opciones == null
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
                    setState(() {
                      _servicio = v;
                      // Otro servicio, otro anticipo: se vuelve a lo de omisión.
                      _pagarTodo = false;
                    });
                    _buscarHorarios();
                  }),
                  if (_servicio?.incluye.isNotEmpty ?? false)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Text(
                        'Incluye: ${_servicio!.incluye.join(' · ')}',
                        style: TextStyle(color: context.colores.textoSuave),
                      ),
                    ),
                  if (_servicio?.conPlan ?? false)
                    Padding(
                      key: const Key('pago-con-bono'),
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Text(
                        'Se descuenta una sesión de tu bono; no pagas al agendar.',
                        style: TextStyle(color: context.colores.textoSuave),
                      ),
                    ),
                  if (opciones.sucursales.length > 1)
                    _selector('Sede', opciones.sucursales, _sede, (v) {
                      setState(() {
                        _sede = v;
                        _ajustarProfesional();
                      });
                      _buscarHorarios();
                    }),
                  if (_profesionalesSede.isEmpty)
                    Padding(
                      key: Key('sede-sin-profesionales'),
                      padding: EdgeInsets.only(bottom: 12),
                      child: Text(
                        'Por ahora nadie atiende en esta sede.',
                        style: TextStyle(color: context.colores.textoSuave),
                      ),
                    )
                  else if (_profesionalesSede.length > 1) ...[
                    ElegirProfesional(
                      profesionales: _profesionalesSede,
                      seleccionado: _idProfesional,
                      alCambiar: (id) {
                        setState(
                          () => _profesional = id == null
                              ? _cualquiera
                              : _profesionalesSede.firstWhere(
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
                  else if (_errorHorarios != null)
                    Row(
                      children: [
                        Expanded(child: Text(_errorHorarios!)),
                        TextButton(
                          onPressed: _buscarHorarios,
                          child: const Text('Reintentar'),
                        ),
                      ],
                    )
                  else if (_listo && _horarios.isEmpty)
                    Text(
                      'No hay horarios libres ese día. Prueba otro día u otro profesional.',
                      style: TextStyle(color: context.colores.textoSuave),
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
                            final elegida = _hora?.inicia == h.inicia;
                            final texto = h.hora;
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
                  if (!_buscando && _horarios.any((h) => h.enOtraZona)) ...[
                    const SizedBox(height: 8),
                    Text(
                      textoHorasDeLaSede,
                      key: Key('horas-de-la-sede'),
                      style: TextStyle(color: context.colores.textoSuave),
                    ),
                  ],
                  const SizedBox(height: 16),
                  // Dónde será (M06): la sede, su dirección y cómo llegar, siempre a la
                  // vista antes de confirmar.
                  if (_sede != null) ...[
                    _DondeSera(sede: _sede!),
                    const SizedBox(height: 12),
                  ],
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
                  if (_anticipo != null)
                    _ElegirAnticipo(
                      servicio: _servicio!,
                      anticipoMinor: _anticipo!,
                      pagarTodo: _pagarTodo,
                      alCambiar: (todo) => setState(() => _pagarTodo = todo),
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

/// Cuánto pagar ahora en un servicio con anticipo (ADR 0161): el anticipo (por
/// omisión, con lo que queda para el negocio) o todo. Lo pagado en línea se devuelve
/// si cancela a tiempo.
class _ElegirAnticipo extends StatelessWidget {
  const _ElegirAnticipo({
    required this.servicio,
    required this.anticipoMinor,
    required this.pagarTodo,
    required this.alCambiar,
  });

  final OpcionCita servicio;
  final int anticipoMinor;
  final bool pagarTodo;
  final ValueChanged<bool> alCambiar;

  @override
  Widget build(BuildContext context) {
    final precio = servicio.precioMinor ?? anticipoMinor;
    String dinero(int minor) => Formato.dinero(minor, servicio.moneda);
    return Column(
      key: const Key('elegir-anticipo'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: 8),
        Text(
          'Cuánto pagar ahora',
          style: Theme.of(context).textTheme.titleSmall,
        ),
        RadioGroup<bool>(
          groupValue: pagarTodo,
          onChanged: (v) => alCambiar(v ?? false),
          child: Column(
            children: [
              RadioListTile<bool>(
                key: const Key('pagar-anticipo'),
                contentPadding: EdgeInsets.zero,
                value: false,
                title: Text('Pagar el anticipo: ${dinero(anticipoMinor)}'),
                subtitle: Text(
                  'El resto (${dinero(precio - anticipoMinor)}) lo pagas en el negocio.',
                ),
              ),
              RadioListTile<bool>(
                key: const Key('pagar-todo'),
                contentPadding: EdgeInsets.zero,
                value: true,
                title: Text('Pagar todo ahora: ${dinero(precio)}'),
              ),
            ],
          ),
        ),
        Text(
          'Si cancelas a tiempo, se te devuelve lo que pagaste; si cancelas '
          'tarde, el negocio lo conserva.',
          style: TextStyle(color: context.colores.textoSuave),
        ),
      ],
    );
  }
}

/// La sede de la cita con su dirección y cómo llegar (M06).
class _DondeSera extends StatelessWidget {
  const _DondeSera({required this.sede});

  final OpcionCita sede;

  @override
  Widget build(BuildContext context) {
    return Container(
      key: const Key('donde-sera'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: Theme.of(context).dividerColor),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.place_outlined, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  sede.nombre,
                  style: const TextStyle(fontWeight: FontWeight.w500),
                ),
                if (sede.ubicacion != null)
                  Text(
                    sede.ubicacion!,
                    style: TextStyle(color: context.colores.textoSuave),
                  ),
                if (sede.mapaUrl != null)
                  TextButton(
                    style: TextButton.styleFrom(padding: EdgeInsets.zero),
                    onPressed: () => launchUrl(
                      Uri.parse(sede.mapaUrl!),
                      mode: LaunchMode.externalApplication,
                    ),
                    child: const Text('Cómo llegar'),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
