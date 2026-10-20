import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/hora_negocio.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/paises.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../cuenta/data/cuenta_models.dart' show HorarioCita;
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import '../data/agenda_repository.dart';
import 'horarios_cita.dart';

/// Abre «Nueva cita»: el negocio agenda a un cliente (recepción, teléfono,
/// mostrador), como en la web. [dia], [profesionalId] y [sucursalId] son lo que se
/// está viendo en la agenda.
Future<void> mostrarNuevaCita(
  BuildContext context, {
  required List<Profesional> profesionales,
  required DateTime dia,
  String? profesionalId,
  String? sucursalId,
}) => showModalBottomSheet<void>(
  context: context,
  isScrollControlled: true,
  showDragHandle: true,
  builder: (_) => NuevaCitaSheet(
    profesionales: profesionales,
    dia: dia,
    profesionalId: profesionalId,
    sucursalId: sucursalId,
  ),
);

/// Cliente (se busca en el servidor o se da de alta al vuelo), servicio,
/// profesional, sucursal, día y hora (un hueco libre u otra hora). La cita queda
/// confirmada; si el servicio es de pago, se cobra en caja. Se confirma con todo a
/// la vista antes de agendar; lo que el servidor no acepta (el hueco ya se ocupó,
/// un bloqueo) se dice aquí y no se pierde lo capturado.
class NuevaCitaSheet extends ConsumerStatefulWidget {
  const NuevaCitaSheet({
    super.key,
    required this.profesionales,
    required this.dia,
    this.profesionalId,
    this.sucursalId,
  });

  final List<Profesional> profesionales;
  final DateTime dia;
  final String? profesionalId;
  final String? sucursalId;

  @override
  ConsumerState<NuevaCitaSheet> createState() => _NuevaCitaSheetState();
}

/// Minutos que se ofrecen cuando el servicio no fija su duración.
const _duraciones = [15, 20, 30, 45, 60, 75, 90, 120, 150, 180];

class _NuevaCitaSheetState extends ConsumerState<NuevaCitaSheet> {
  // Catálogo y sucursales.
  List<ServicioCita>? _servicios;
  List<SucursalAgenda> _sucursales = const [];
  bool _cargando = true;
  String? _errorCarga;

  ServicioCita? _servicio;
  String? _profesionalId;
  SucursalAgenda? _sucursal;
  late DateTime _dia;

  /// Solo si el servicio no fija la suya (la de la web por omisión).
  int _duracion = 30;

  // Horarios libres del día y la hora elegida (de pared, de la sucursal).
  List<HorarioCita> _horarios = const [];
  bool _buscandoHoras = false;
  String? _errorHoras;
  DateTime? _hora;
  int _pedidoHoras = 0;

  // Cliente: uno que ya existe o uno nuevo (alta rápida).
  ClienteAgenda? _cliente;
  bool _nuevo = false;
  final _buscar = TextEditingController();
  final _nombre = TextEditingController();
  final _celular = TextEditingController();

  /// Aceptó los avisos por WhatsApp (ADR 0069): solo si el negocio los manda y hay
  /// celular.
  bool _aceptaWhatsApp = false;
  final _correo = TextEditingController();
  Timer? _espera;
  List<ClienteAgenda> _resultados = const [];
  bool _buscandoClientes = false;
  String? _errorClientes;
  int _pedidoClientes = 0;

  bool _guardando = false;
  String? _error;

  AgendaRepository? get _repo => ref.read(agendaRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _dia = DateTime(widget.dia.year, widget.dia.month, widget.dia.day);
    _profesionalId =
        widget.profesionales.any((p) => p.id == widget.profesionalId)
        ? widget.profesionalId
        : widget.profesionales.firstOrNull?.id;
    _cargar();
  }

  @override
  void dispose() {
    _espera?.cancel();
    _buscar.dispose();
    _nombre.dispose();
    _celular.dispose();
    _correo.dispose();
    super.dispose();
  }

  Future<void> _cargar() async {
    final repo = _repo;
    if (repo == null) {
      return;
    }
    setState(() {
      _cargando = true;
      _errorCarga = null;
    });
    try {
      final listas = await Future.wait<Object>([
        repo.serviciosParaCita(),
        repo.sucursales(),
      ]);
      final servicios = listas[0] as List<ServicioCita>;
      final sucursales = listas[1] as List<SucursalAgenda>;
      if (!mounted) {
        return;
      }
      setState(() {
        _servicios = servicios;
        _sucursales = sucursales;
        // Como en la web: el primer servicio y la sucursal que se está viendo.
        _servicio = servicios.firstOrNull;
        _sucursal =
            sucursales.where((s) => s.id == widget.sucursalId).firstOrNull ??
            sucursales.firstOrNull;
        _cargando = false;
      });
      await _buscarHorarios();
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
    }
  }

  /// La duración que se usa: la del servicio o, si no tiene, la elegida.
  int get _minutos => _servicio?.duracionMinutos ?? _duracion;

  /// Busca los huecos libres de lo elegido. Con [conservar], la hora elegida se
  /// queda si sigue libre (o si era «Otra hora»).
  Future<void> _buscarHorarios({bool conservar = false}) async {
    final repo = _repo;
    final servicio = _servicio;
    final profesional = _profesionalId;
    final sucursal = _sucursal;
    final antes = _hora;
    // ¿La hora elegida era uno de los huecos? (si no, se eligió con «Otra hora»).
    final eraHueco =
        antes != null &&
        _horarios.any((h) => paredDeHorario(h.iniciaEnLocal) == antes);
    setState(() {
      _horarios = const [];
      if (!conservar) {
        // Otro servicio, persona, sucursal o día: otra hora y sin el aviso de antes.
        _hora = null;
        _error = null;
      }
      _errorHoras = null;
    });
    if (repo == null ||
        servicio == null ||
        profesional == null ||
        sucursal == null) {
      return;
    }
    final pedido = ++_pedidoHoras;
    setState(() => _buscandoHoras = true);
    try {
      final horarios = await repo.horariosLibres(
        instructorId: profesional,
        sucursalId: sucursal.id,
        fecha: _dia,
        ofertaId: servicio.id,
        duracionMinutos: _minutos,
      );
      if (!mounted || pedido != _pedidoHoras) {
        return;
      }
      setState(() {
        _horarios = horarios;
        _buscandoHoras = false;
        if (conservar && eraHueco) {
          // Un hueco que se ocupó mientras tanto ya no se ofrece.
          final sigueLibre = horarios.any(
            (h) => paredDeHorario(h.iniciaEnLocal) == antes,
          );
          _hora = sigueLibre ? antes : null;
        }
      });
    } on DioException catch (e) {
      if (mounted && pedido == _pedidoHoras) {
        setState(() {
          _errorHoras = mensajeDeError(
            e,
            porDefecto: 'No se pudieron cargar los horarios.',
          );
          _buscandoHoras = false;
        });
      }
    }
  }

  // ------------------------------------------------------------- cliente

  void _alEscribir(String texto) {
    _espera?.cancel();
    final t = texto.trim();
    if (t.length < 2) {
      _pedidoClientes++;
      setState(() {
        _resultados = const [];
        _errorClientes = null;
        _buscandoClientes = false;
      });
      return;
    }
    setState(() => _buscandoClientes = true);
    _espera = Timer(
      const Duration(milliseconds: 250),
      () => _buscarClientes(t),
    );
  }

  Future<void> _buscarClientes(String texto) async {
    final repo = _repo;
    if (repo == null) {
      return;
    }
    final pedido = ++_pedidoClientes;
    try {
      final encontrados = await repo.buscarClientes(texto);
      if (!mounted || pedido != _pedidoClientes) {
        return;
      }
      setState(() {
        _resultados = encontrados;
        _errorClientes = null;
        _buscandoClientes = false;
      });
    } on DioException catch (e) {
      if (mounted && pedido == _pedidoClientes) {
        setState(() {
          _errorClientes = mensajeDeError(e, porDefecto: 'No se pudo buscar.');
          _buscandoClientes = false;
        });
      }
    }
  }

  void _elegirCliente(ClienteAgenda c) => setState(() {
    _cliente = c;
    _resultados = const [];
    _buscar.clear();
    _error = null;
  });

  /// Como en la web: se pregunta por WhatsApp si el negocio manda avisos por ahí y
  /// el cliente dejó celular.
  bool get _ofrecerWhatsApp =>
      (ref.read(sesionProvider)?.whatsappClientes ?? false) &&
      _celular.text.trim().isNotEmpty;

  /// Alta rápida: lo escrito en el buscador pasa a su campo (nombre, celular o
  /// correo) para no escribirlo dos veces.
  void _abrirNuevo() {
    final t = _buscar.text.trim();
    setState(() {
      _nuevo = true;
      _error = null;
      if (t.contains('@')) {
        _correo.text = t;
      } else if (RegExp(r'^[\d\s+()-]+$').hasMatch(t)) {
        _celular.text = t;
      } else {
        _nombre.text = t;
      }
    });
  }

  // ------------------------------------------------------------- día y hora

  /// Hoy y ahora en la sucursal (los de la agenda; sin sesiones, los del negocio).
  (DateTime, DateTime) _hoyYAhora() {
    final ahora = DateTime.now();
    final agenda = ref.read(agendaProvider).value;
    final sesion = ref.read(sesionProvider);
    final pared =
        agenda?.sesiones
            .where((s) => s.desfaseMin != null)
            .firstOrNull
            ?.enSucursal(ahora) ??
        sesion?.enNegocio(ahora) ??
        ahora;
    return (DateTime(pared.year, pared.month, pared.day), pared);
  }

  Future<void> _elegirDia() async {
    final (hoy, _) = _hoyYAhora();
    final dia = await elegirDiaCita(context, inicial: _dia, hoy: hoy);
    if (dia != null && mounted) {
      setState(() => _dia = dia);
      await _buscarHorarios();
    }
  }

  Future<void> _otraHora() async {
    final (_, ahora) = _hoyYAhora();
    final hora = await elegirOtraHora(
      context,
      dia: _dia,
      inicial: _hora ?? horaSugerida(_dia, ahora),
    );
    if (hora != null && mounted) {
      setState(() {
        _hora = hora;
        _error = null;
      });
    }
  }

  // ------------------------------------------------------------- agendar

  bool get _listo =>
      (_cliente != null || (_nuevo && _nombre.text.trim().isNotEmpty)) &&
      _servicio != null &&
      _profesionalId != null &&
      _sucursal != null &&
      _hora != null;

  Future<void> _agendar() async {
    final repo = _repo;
    final servicio = _servicio;
    final sucursal = _sucursal;
    final profesional = _profesionalId;
    final hora = _hora;
    if (repo == null ||
        servicio == null ||
        sucursal == null ||
        profesional == null ||
        hora == null ||
        !_listo) {
      return;
    }
    final moneda = ref.read(sesionProvider)?.moneda ?? 'MXN';
    final nombre = _nuevo ? _nombre.text.trim() : _cliente!.nombre;
    final si = await confirmarCita(
      context,
      titulo: 'Agendar cita',
      filas: [
        (
          ref.read(sesionProvider)?.terminologia.miembro ?? 'Cliente',
          _nuevo ? '$nombre (se da de alta)' : nombre,
        ),
        ('Servicio', servicio.nombre),
        ('Con', nombreProfesional(widget.profesionales, profesional)),
        if (_sucursales.length > 1) ('Sucursal', sucursal.nombre),
        ('Cuándo', '${Formato.diaHora(hora)} · $_minutos min'),
        (
          'Precio',
          servicio.seCobraEnCaja
              ? '${Formato.dinero(servicio.precioMinor ?? 0, moneda)}, se '
                    'cobra en caja'
              : 'Usa un crédito de su membresía',
        ),
      ],
      aceptar: 'Agendar',
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
    final controlador = ref.read(agendaProvider.notifier);
    var paso = 'cliente';
    try {
      var cliente = _cliente;
      if (cliente == null) {
        final celular = _celular.text.trim();
        final correo = _correo.text.trim();
        cliente = await repo.crearCliente(
          nombre: nombre,
          celular: celular.isEmpty ? null : celular,
          email: correo.isEmpty ? null : correo,
          sucursalId: sucursal.id,
          aceptaWhatsApp: _ofrecerWhatsApp && _aceptaWhatsApp,
        );
        // Ya existe: si la cita no se puede agendar (el hueco se ocupó), al
        // reintentar se usa este cliente y no se crea otro igual.
        if (mounted) {
          final creado = cliente;
          setState(() {
            _cliente = creado;
            _nuevo = false;
          });
        }
      }
      paso = 'cita';
      await controlador.agendarCita(
        NuevaCita(
          personaId: cliente.id,
          ofertaId: servicio.id,
          sucursalId: sucursal.id,
          instructorId: profesional,
          inicia: hora,
          duracionMinutos: _minutos,
        ),
      );
      mensajero.showSnackBar(
        SnackBar(
          content: Text(
            'Cita agendada: ${cliente.nombre} a las ${Formato.hora(hora)}.',
          ),
        ),
      );
      if (mounted) {
        navegador.pop();
      }
    } on DioException catch (e) {
      if (!mounted) {
        return;
      }
      setState(
        () => _error = mensajeDeError(
          e,
          porDefecto: paso == 'cliente'
              ? 'No se pudo dar de alta.'
              : 'No se pudo agendar la cita.',
        ),
      );
      // El hueco pudo ocuparse mientras tanto: se vuelven a pedir los libres.
      if (paso == 'cita') {
        await _buscarHorarios(conservar: true);
      }
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
      }
    }
  }

  // ------------------------------------------------------------- vista

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    final servicios = _servicios;
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
                    onPressed: _cargar,
                    child: const Text('Reintentar'),
                  ),
                ],
              ),
            )
          : _cargando || servicios == null
          ? const SizedBox(
              height: 160,
              child: Center(child: CircularProgressIndicator()),
            )
          : servicios.isEmpty || _sucursales.isEmpty
          ? Padding(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: Text(
                servicios.isEmpty
                    ? 'Aún no hay servicios para agendar. Agrégalos en el '
                          'catálogo desde la web.'
                    : 'Aún no hay sucursales para agendar.',
              ),
            )
          : SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    'Nueva cita',
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  const SizedBox(height: 16),
                  _seccionCliente(sesion),
                  const SizedBox(height: 16),
                  _servicioYQuien(servicios, sesion),
                  BotonDiaCita(
                    dia: _dia,
                    alTocar: _guardando ? null : _elegirDia,
                  ),
                  const SizedBox(height: 12),
                  HorariosCita(
                    cargando: _buscandoHoras,
                    error: _errorHoras,
                    horarios: _horarios,
                    elegida: _hora,
                    alElegir: (h) => setState(() {
                      _hora = h;
                      _error = null;
                    }),
                    alOtraHora: _guardando ? null : _otraHora,
                    alReintentar: _buscarHorarios,
                    vacio:
                        'No hay horarios libres ese día con '
                        '${nombreProfesional(widget.profesionales, _profesionalId)}. '
                        'Prueba otro día, otra persona u otra hora.',
                    etiquetaZona: _horarios.any((h) => h.enOtraZona)
                        ? HoraNegocio.etiqueta([_sucursal?.nombre])
                        : null,
                  ),
                  if (_servicio case final s?) ...[
                    const SizedBox(height: 16),
                    Container(
                      key: const Key('aviso-servicio'),
                      padding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 10,
                      ),
                      decoration: BoxDecoration(
                        color: context.colores.superficie2,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Text(s.aviso(sesion?.moneda ?? 'MXN')),
                    ),
                  ],
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      _error!,
                      key: const Key('error-nueva-cita'),
                      style: TextStyle(color: context.colores.error),
                    ),
                  ],
                  const SizedBox(height: 16),
                  FilledButton(
                    key: const Key('agendar-cita'),
                    onPressed: _listo && !_guardando ? _agendar : null,
                    child: Text(_guardando ? 'Agendando…' : 'Agendar cita'),
                  ),
                ],
              ),
            ),
    );
  }

  /// Servicio, quién atiende, sucursal y (si el servicio no la fija) duración.
  Widget _servicioYQuien(List<ServicioCita> servicios, Sesion? sesion) {
    final moneda = sesion?.moneda ?? 'MXN';
    final terminos = sesion?.terminologia ?? const Terminologia();
    InputDecoration etiqueta(String texto) => InputDecoration(labelText: texto);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: DropdownButtonFormField<ServicioCita>(
            key: const Key('servicio-cita'),
            initialValue: _servicio,
            isExpanded: true,
            decoration: etiqueta('Servicio'),
            items: [
              for (final s in servicios)
                DropdownMenuItem(
                  value: s,
                  child: Text(
                    s.etiqueta(moneda),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
            ],
            onChanged: _guardando
                ? null
                : (s) {
                    setState(() => _servicio = s);
                    _buscarHorarios();
                  },
          ),
        ),
        // Con una sola persona que atiende, no hay a quién elegir (como en la web).
        if (widget.profesionales.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: DropdownButtonFormField<String>(
              key: const Key('profesional-cita'),
              initialValue: _profesionalId,
              isExpanded: true,
              decoration: etiqueta(terminos.instructor),
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
        if (_sucursales.length > 1)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: DropdownButtonFormField<SucursalAgenda>(
              key: const Key('sucursal-cita'),
              initialValue: _sucursal,
              isExpanded: true,
              decoration: etiqueta('Sucursal'),
              items: [
                for (final s in _sucursales)
                  DropdownMenuItem(value: s, child: Text(s.nombre)),
              ],
              onChanged: _guardando
                  ? null
                  : (s) {
                      setState(() => _sucursal = s);
                      _buscarHorarios();
                    },
            ),
          ),
        if (_servicio != null && _servicio!.duracionMinutos == null)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: DropdownButtonFormField<int>(
              key: const Key('duracion-cita'),
              initialValue: _duracion,
              decoration: etiqueta('Duración'),
              items: [
                for (final m in _duraciones)
                  DropdownMenuItem(value: m, child: Text('$m min')),
              ],
              onChanged: _guardando
                  ? null
                  : (m) {
                      setState(() => _duracion = m ?? _duracion);
                      _buscarHorarios();
                    },
            ),
          ),
      ],
    );
  }

  /// A quién: el elegido, el alta rápida o el buscador.
  Widget _seccionCliente(Sesion? sesion) {
    final miembro = (sesion?.terminologia ?? const Terminologia()).miembro;
    final suave = TextStyle(color: context.colores.textoSuave);
    final cliente = _cliente;
    if (cliente != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(miembro, style: Theme.of(context).textTheme.titleSmall),
          ListTile(
            key: const Key('cliente-elegido'),
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(
              child: Text(
                Profesional(id: '', nombre: cliente.nombre).iniciales,
              ),
            ),
            title: Text(cliente.nombre),
            subtitle: cliente.detalle == null ? null : Text(cliente.detalle!),
            trailing: TextButton(
              onPressed: _guardando
                  ? null
                  : () => setState(() => _cliente = null),
              child: const Text('Cambiar'),
            ),
          ),
        ],
      );
    }
    if (_nuevo) {
      return Container(
        key: const Key('alta-rapida'),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          border: Border.all(color: Theme.of(context).dividerColor),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              '$miembro nuevo',
              style: Theme.of(context).textTheme.titleSmall,
            ),
            TextField(
              key: const Key('nuevo-nombre'),
              controller: _nombre,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(labelText: 'Nombre'),
              onChanged: (_) => setState(() {}),
            ),
            TextField(
              key: const Key('nuevo-celular'),
              controller: _celular,
              keyboardType: TextInputType.phone,
              decoration: InputDecoration(
                labelText: 'Celular (opcional)',
                helperText: sesion == null
                    ? null
                    : Paises.ayudaCelular(pais: sesion.pais, lada: sesion.lada),
                helperMaxLines: 2,
              ),
              onChanged: (_) => setState(() {}),
            ),
            if (_ofrecerWhatsApp)
              CheckboxListTile(
                key: const Key('acepta-whatsapp'),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: _aceptaWhatsApp,
                onChanged: _guardando
                    ? null
                    : (v) => setState(() => _aceptaWhatsApp = v ?? false),
                title: const Text('Acepta recibir avisos por WhatsApp'),
              ),
            TextField(
              key: const Key('nuevo-correo'),
              controller: _correo,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(labelText: 'Correo (opcional)'),
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: _guardando
                    ? null
                    : () => setState(() => _nuevo = false),
                child: const Text('Buscar uno existente'),
              ),
            ),
          ],
        ),
      );
    }
    final texto = _buscar.text.trim();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('buscar-cliente'),
          controller: _buscar,
          decoration: InputDecoration(
            labelText: miembro,
            hintText: 'Buscar por nombre, correo o celular',
            prefixIcon: const Icon(Icons.search),
          ),
          onChanged: _alEscribir,
        ),
        if (_buscandoClientes) const LinearProgressIndicator(minHeight: 2),
        if (_errorClientes != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(_errorClientes!, style: suave),
          )
        else if (!_buscandoClientes && texto.length >= 2 && _resultados.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              'Nadie coincide con «$texto».',
              key: const Key('sin-clientes'),
              style: suave,
            ),
          ),
        for (final c in _resultados)
          ListTile(
            key: Key('cliente-${c.id}'),
            dense: true,
            contentPadding: EdgeInsets.zero,
            title: Text(c.nombre),
            subtitle: c.detalle == null ? null : Text(c.detalle!),
            onTap: () => _elegirCliente(c),
          ),
        // Dar de alta lo decide quien puede gestionar clientes (como en la web).
        if (sesion?.puede('miembros.gestionar') ?? false)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const Key('cliente-nuevo'),
              icon: const Icon(Icons.person_add_alt, size: 18),
              label: Text('$miembro nuevo'),
              onPressed: _abrirNuevo,
            ),
          ),
      ],
    );
  }
}
