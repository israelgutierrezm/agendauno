import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import '../data/agenda_repository.dart';
import 'buscar_persona.dart';

/// Lista de una clase para el pase de lista (por sesión).
final rosterProvider = FutureProvider.autoDispose.family<ListaClase, String>((
  ref,
  sesionId,
) async {
  final repo = ref.watch(agendaRepositoryProvider);
  if (repo == null) {
    return const ListaClase(asistentes: []);
  }
  return repo.roster(sesionId);
});

/// Lo que los clientes apartaron para recoger en la clase (ADR 0194), con el
/// permiso de su ruta (pasar lista). Si no se pudo leer, nada: la lista sigue.
final apartadosClaseProvider = FutureProvider.autoDispose
    .family<List<ApartadoClase>, String>((ref, sesionId) async {
      final repo = ref.watch(agendaRepositoryProvider);
      final sesion = ref.watch(sesionProvider);
      if (repo == null ||
          sesion == null ||
          sesion.esCitas ||
          !sesion.puede('asistencia.marcar')) {
        return const [];
      }
      try {
        return await repo.apartados(sesionId);
      } on Object {
        return const [];
      }
    });

/// Pase de lista de una clase, como el de la web: quién llegó (botón grande por
/// persona), con avisos de primera vez y adeudo; los invitados de los clientes y
/// quien reservó desde Wellhub, TotalPass o Fitpass (ocupan lugar: el cupo los
/// cuenta); lo apartado para recoger; agregar a quien llegó sin reservar y la lista
/// de espera. Cada toque registra la asistencia al momento.
class PaseListaScreen extends ConsumerStatefulWidget {
  const PaseListaScreen({super.key, required this.sesion});

  final SesionAgenda sesion;

  @override
  ConsumerState<PaseListaScreen> createState() => _PaseListaScreenState();
}

class _PaseListaScreenState extends ConsumerState<PaseListaScreen> {
  final _marcando = <String>{};

  Terminologia get _terminos =>
      ref.read(sesionProvider)?.terminologia ?? const Terminologia();

  bool _puede(String permiso) =>
      ref.watch(sesionProvider)?.puede(permiso) ?? false;

  void _recargar() {
    ref.invalidate(rosterProvider(widget.sesion.id));
    ref.invalidate(apartadosClaseProvider(widget.sesion.id));
    ref.invalidate(agendaProvider);
  }

  Future<void> _terminarLista() async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final mensajero = ScaffoldMessenger.of(context);
    try {
      final cuantos = await repo.terminarLista(widget.sesion.id);
      _recargar();
      mensajero.showSnackBar(
        SnackBar(
          content: Text(
            cuantos == 0
                ? 'Lista terminada.'
                : (cuantos == 1
                      ? 'Lista terminada: 1 persona quedó como «no vino».'
                      : 'Lista terminada: $cuantos personas quedaron como «no vino».'),
          ),
        ),
      );
    } on DioException catch (e) {
      mensajero.showSnackBar(
        SnackBar(
          content: Text(
            mensajeDeError(e, porDefecto: 'No se pudo terminar la lista.'),
          ),
        ),
      );
    }
  }

  /// Pregunta antes de algo que avisa a alguien, usa su plan o libera un lugar.
  Future<bool> _confirmar(
    String mensaje,
    String aceptar, {
    bool peligro = false,
  }) async =>
      await showDialog<bool>(
        context: context,
        builder: (contexto) => AlertDialog(
          content: Text(mensaje),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(contexto).pop(false),
              child: const Text('Cancelar'),
            ),
            FilledButton(
              style: peligro
                  ? FilledButton.styleFrom(
                      backgroundColor: contexto.colores.error,
                    )
                  : null,
              onPressed: () => Navigator.of(contexto).pop(true),
              child: Text(aceptar),
            ),
          ],
        ),
      ) ==
      true;

  /// Ejecuta una acción de la lista y la vuelve a leer; si falla, lo dice.
  Future<void> _hacer(
    Future<String?> Function(AgendaRepository repo) accion,
  ) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final mensajero = ScaffoldMessenger.of(context);
    try {
      final ok = await accion(repo);
      _recargar();
      if (ok != null) {
        mensajero.showSnackBar(SnackBar(content: Text(ok)));
      }
    } on DioException catch (e) {
      mensajero.showSnackBar(
        SnackBar(
          content: Text(mensajeDeError(e, porDefecto: 'No se pudo completar.')),
        ),
      );
    }
  }

  /// Los lugares libres, a la lista de espera en su orden (se les avisa).
  Future<void> _promover() async {
    if (!await _confirmar(
      '¿Ofrecer los lugares libres a la lista de espera? Se avisa a quienes '
          'siguen en la fila.',
      'Ofrecer lugares',
    )) {
      return;
    }
    await _hacer((r) async {
      final n = await r.promover(widget.sesion.id);
      return switch (n) {
        0 =>
          'No hay a quién ofrecer en esta ${_terminos.sesion.toLowerCase()}.',
        1 => 'Se ofreció 1 lugar a la lista de espera.',
        _ => 'Se ofrecieron $n lugares a la lista de espera.',
      };
    });
  }

  /// Aceptar a su nombre confirma su lugar y usa su plan: se confirma antes.
  Future<void> _aceptar(Asistente a) async {
    final clase = _terminos.sesion.toLowerCase();
    if (!await _confirmar(
      '¿Aceptar el lugar a nombre de ${a.nombre}? Queda confirmado en la $clase '
          'y se usa su plan, como si lo hubiera aceptado.',
      'Aceptar el lugar',
    )) {
      return;
    }
    await _hacer((r) async {
      await r.aceptarLugar(a.reservaId);
      return 'Lugar aceptado a nombre de ${a.nombre}.';
    });
  }

  /// Qué pasaría si no viene; null si no se pudo leer (se usa el texto general).
  Future<EfectoInasistencia?> _efectoDeFalta(
    AgendaRepository repo,
    String reservaId,
  ) async {
    try {
      return await repo.efectoDeInasistencia(reservaId);
    } on DioException {
      return null;
    }
  }

  Future<void> _marcar(
    Asistente a,
    String estado, {
    bool retardo = false,
  }) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    // Marcar o corregir mueve créditos, como en la web: «No vino» se confirma con
    // su efecto exacto (según su política) y una corrección (llegó ↔ no vino),
    // con el ajuste. «Llegó» la primera vez no pregunta.
    String nombre(String e) =>
        e == 'presente' ? (retardo ? 'Llegó tarde' : 'Llegó') : 'No vino';
    final correccion = a.asistencia != null && a.asistencia != estado;
    if (estado == 'ausente' || correccion) {
      final String mensaje;
      if (correccion) {
        mensaje =
            '${a.nombre} está marcado como «${a.asistencia == 'presente' ? 'Llegó' : 'No vino'}». '
            '¿Cambiarlo a «${nombre(estado)}»? Si aplica, el crédito se ajusta en '
            'su saldo.';
      } else {
        final efecto = await _efectoDeFalta(repo, a.reservaId);
        if (!mounted) {
          return;
        }
        mensaje =
            '¿Marcar que ${a.nombre} no vino? '
            '${efecto?.texto ?? 'Según la política del negocio, puede perder el crédito.'}';
      }
      if (!await _confirmar(mensaje, nombre(estado)) || !mounted) {
        return;
      }
    }
    setState(() => _marcando.add(a.reservaId));
    final mensajero = ScaffoldMessenger.of(context);
    try {
      await repo.marcarAsistencia(a.reservaId, estado, retardo: retardo);
      _recargar();
    } on DioException catch (e) {
      mensajero.showSnackBar(
        SnackBar(
          content: Text(mensajeDeError(e, porDefecto: 'No se pudo marcar.')),
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _marcando.remove(a.reservaId));
      }
    }
  }

  /// Un invitado llegó o no vino (no usa créditos de nadie: usó un pase).
  Future<void> _marcarInvitado(InvitadoClase i, bool asistio) async {
    setState(() => _marcando.add(i.id));
    await _hacer((r) async {
      await r.marcarInvitado(i.id, asistio: asistio);
      return null;
    });
    if (mounted) {
      setState(() => _marcando.remove(i.id));
    }
  }

  /// Llegó quien reservó desde su plataforma. Fitpass pide revisar su
  /// identificación oficial antes.
  Future<void> _llegoDePlataforma(LugarPlataforma l) async {
    if (l.pideIdentificacion &&
        !await _confirmar(
          'Fitpass pide revisar la identificación oficial de quien llega. '
              '¿Ya la revisaste?',
          'Sí, la revisé',
        )) {
      return;
    }
    setState(() => _marcando.add(l.id));
    await _hacer((r) async {
      await r.llegoDePlataforma(
        l.id,
        identificacionRevisada: l.pideIdentificacion,
      );
      return null;
    });
    if (mounted) {
      setState(() => _marcando.remove(l.id));
    }
  }

  /// Cancela la reserva de la plataforma: le avisa y el lugar queda libre.
  Future<void> _cancelarDePlataforma(LugarPlataforma l) async {
    if (!await _confirmar(
      '¿Cancelar la reserva de ${l.nombreVisible} (${l.proveedorNombre})? '
          'La plataforma le avisará que su reserva se canceló y el lugar queda '
          'libre.',
      'Cancelar reserva',
      peligro: true,
    )) {
      return;
    }
    await _hacer((r) async {
      await r.cancelarDePlataforma(l.id);
      return 'Reserva de ${l.nombreVisible} cancelada.';
    });
  }

  /// Agregar a quien llegó sin reservar (como en la web): se busca y se reserva
  /// con su plan; si ya no hay lugar, entra a la lista de espera.
  Future<void> _agregar({required bool llena}) async {
    final mensajero = ScaffoldMessenger.of(context);
    final agregado = await showModalBottomSheet<({String nombre, bool espera})>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => AgregarAClaseSheet(
        sesionId: widget.sesion.id,
        llena: llena,
        terminos: _terminos,
      ),
    );
    if (agregado == null || !mounted) {
      return;
    }
    _recargar();
    mensajero.showSnackBar(
      SnackBar(
        content: Text(
          agregado.espera
              ? '${agregado.nombre} quedó en la lista de espera.'
              : '${agregado.nombre} se agregó a la ${_terminos.sesion.toLowerCase()}.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.sesion;
    final tono = TonoServicio.de(s.ofertaId).en(context.colores);
    final roster = ref.watch(rosterProvider(s.id));
    final terminos =
        ref.watch(sesionProvider)?.terminologia ?? const Terminologia();
    final apartados = ref.watch(apartadosClaseProvider(s.id)).value ?? const [];
    String hhmm(DateTime d) =>
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

    return Scaffold(
      backgroundColor: context.colores.fondo,
      appBar: AppBar(
        title: const Text('Pase de lista'),
        backgroundColor: context.colores.fondo,
      ),
      body: roster.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorDeCarga(
          mensaje: 'No se pudo cargar la lista.',
          onReintentar: () => ref.invalidate(rosterProvider(s.id)),
        ),
        data: (lista) {
          final enSala = lista.asistentes.where((a) => a.enSala).toList();
          final enEspera = lista.asistentes.where((a) => !a.enSala).toList();
          final abierta = lista.abierta(DateTime.now());
          final cancelada = lista.cancelada || s.estado == 'cancelada';
          final gestionar = _puede('reservas.gestionar') && !cancelada;
          final marcar = _puede('asistencia.marcar') && !cancelada;
          // El cupo cuadra con la lista: reservas, invitados y plataformas.
          final libres = lista.libres(s.capacidad);
          // Quién ocupa lugar y si ya llegó, para la barra de avance.
          final llegadas = [
            for (final a in enSala) a.llego,
            for (final i in lista.invitados.where((i) => i.ocupaLugar)) i.llego,
            for (final l in lista.plataforma.where((l) => l.ocupaLugar))
              l.llego,
          ];
          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
            children: [
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: tono.fondo,
                  borderRadius: BorderRadius.circular(18),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                s.oferta ?? '—',
                                style: TextStyle(
                                  color: tono.tinta,
                                  fontSize: 20,
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                              Text(
                                '${hhmm(s.iniciaLocal)}–${hhmm(s.terminaLocal)}${s.sala != null ? ' · ${s.sala}' : ''}',
                                style: TextStyle(
                                  color: tono.tinta,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              // El teléfono está en otra zona: es la hora de la
                              // sucursal.
                              if (s.etiquetaHora() case final etiqueta?)
                                Text(
                                  etiqueta,
                                  key: const Key('hora-de-la-sucursal'),
                                  style: TextStyle(
                                    color: tono.tinta.withValues(alpha: 0.85),
                                    fontSize: 12,
                                  ),
                                ),
                            ],
                          ),
                        ),
                        Semantics(
                          label:
                              'Llegaron ${lista.llegaron} de ${lista.ocupan}',
                          child: ExcludeSemantics(
                            child: Text(
                              '${lista.llegaron}/${lista.ocupan}',
                              key: const Key('llegaron-de'),
                              style: TextStyle(
                                color: tono.tinta,
                                fontSize: 26,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        for (final llego in llegadas)
                          Expanded(
                            child: Container(
                              height: 8,
                              margin: const EdgeInsets.symmetric(
                                horizontal: 1.5,
                              ),
                              decoration: BoxDecoration(
                                color: llego
                                    ? context.colores.exito
                                    : context.colores.superficie.withValues(
                                        alpha: 0.85,
                                      ),
                                borderRadius: BorderRadius.circular(99),
                              ),
                            ),
                          ),
                      ],
                    ),
                    if (libres != null) ...[
                      const SizedBox(height: 8),
                      Text(
                        libres == 0
                            ? 'Llena'
                            : (libres == 1
                                  ? '1 lugar libre'
                                  : '$libres lugares libres'),
                        key: const Key('lugares-libres'),
                        style: TextStyle(
                          color: tono.tinta,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: 12),
              if (cancelada)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    'Esta ${terminos.sesion.toLowerCase()} se canceló.',
                    style: TextStyle(color: context.colores.error),
                  ),
                ),
              // Antes de la ventana (ADR 0101), la lista aún no se pasa.
              if (!abierta && lista.asistenciaDesde != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    'La lista se abre a las ${hhmm(s.enSucursal(lista.asistenciaDesde!))}.',
                    style: TextStyle(color: context.colores.textoSuave),
                  ),
                ),
              // Quien llegó sin reservar (como en la web), con el permiso de
              // reservar.
              if (gestionar)
                Align(
                  alignment: Alignment.centerLeft,
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: OutlinedButton.icon(
                      key: const Key('agregar-a-clase'),
                      onPressed: () => _agregar(llena: libres == 0),
                      icon: const Icon(Icons.person_add_alt),
                      label: Text('Agregar ${terminos.miembro.toLowerCase()}'),
                    ),
                  ),
                ),
              if (enSala.isEmpty &&
                  lista.invitados.isEmpty &&
                  lista.plataforma.isEmpty)
                Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    'Nadie reservó esta ${terminos.sesion.toLowerCase()} todavía.',
                    textAlign: TextAlign.center,
                  ),
                ),
              for (final a in enSala)
                _fila(
                  a,
                  abierta: abierta,
                  marcar: marcar,
                  // Empezada la clase, ya no se ofrecen ni se aceptan lugares: el
                  // servidor lo rechaza (ADR 0095).
                  gestionar: gestionar && !lista.empezo,
                ),
              // Invitados de los clientes (ADR 0182): no son clientes, ocupan
              // lugar.
              if (lista.invitados.isNotEmpty)
                _Seccion(
                  key: const Key('invitados-clase'),
                  titulo: 'Invitados · ${lista.invitados.length}',
                  ayuda:
                      'Vienen con el pase de invitado de un ${terminos.miembro.toLowerCase()}: '
                      'ocupan lugar y no son ${terminos.miembros.toLowerCase()}.',
                  hijos: [
                    for (final i in lista.invitados)
                      _FilaVisitante(
                        key: ValueKey('invitado-${i.id}'),
                        nombre: i.nombre,
                        detalle: i.anfitrion == null
                            ? null
                            : 'invitado de ${i.anfitrion}',
                        estado: i.estadoTexto,
                        ocupado: _marcando.contains(i.id),
                        acciones: [
                          if (marcar && abierta) ...[
                            TextButton(
                              onPressed:
                                  i.estado == 'asistio' ||
                                      _marcando.contains(i.id)
                                  ? null
                                  : () => _marcarInvitado(i, true),
                              child: const Text('Llegó'),
                            ),
                            TextButton(
                              onPressed:
                                  i.estado == 'no_asistio' ||
                                      _marcando.contains(i.id)
                                  ? null
                                  : () => _marcarInvitado(i, false),
                              child: const Text('No vino'),
                            ),
                          ],
                        ],
                      ),
                  ],
                ),
              // Reservas desde la app de una plataforma (ADR 0202).
              if (lista.plataforma.isNotEmpty)
                _Seccion(
                  key: const Key('plataforma-clase'),
                  titulo:
                      'Reservas de plataformas · ${lista.plataforma.length}',
                  ayuda:
                      'Reservaron desde la app de su plataforma: ocupan lugar y no '
                      'son ${terminos.miembros.toLowerCase()} del negocio.',
                  hijos: [
                    for (final l in lista.plataforma)
                      _FilaVisitante(
                        key: ValueKey('plataforma-${l.id}'),
                        nombre: l.nombreVisible,
                        detalle: l.proveedorNombre,
                        estado: l.estadoTexto,
                        ocupado: _marcando.contains(l.id),
                        acciones: [
                          if (l.porMarcar &&
                              !cancelada &&
                              _puede('checkins.registrar'))
                            l.puedeMarcar
                                ? TextButton(
                                    onPressed: _marcando.contains(l.id)
                                        ? null
                                        : () => _llegoDePlataforma(l),
                                    child: const Text('Llegó'),
                                  )
                                : Text(
                                    'Hace check-in en su app',
                                    style: TextStyle(
                                      fontSize: 12,
                                      color: context.colores.textoSuave,
                                    ),
                                  ),
                          if (l.porMarcar && gestionar)
                            TextButton(
                              style: TextButton.styleFrom(
                                foregroundColor: context.colores.error,
                              ),
                              onPressed: _marcando.contains(l.id)
                                  ? null
                                  : () => _cancelarDePlataforma(l),
                              child: const Text('Cancelar reserva'),
                            ),
                        ],
                      ),
                  ],
                ),
              // Lo apartado para recoger (ADR 0194): se cobra en el mostrador.
              if (apartados.isNotEmpty)
                _Seccion(
                  key: const Key('apartados-clase'),
                  titulo:
                      'Apartados para recoger · ${ApartadoClase.porPersona(apartados).length}',
                  ayuda: 'Se pagan en el mostrador al entregarlos.',
                  hijos: [
                    for (final grupo in ApartadoClase.porPersona(apartados))
                      _FilaVisitante(
                        key: ValueKey('apartado-${grupo.first.id}'),
                        nombre: grupo.first.persona ?? '—',
                        detalle: grupo.map((a) => a.texto).join(' · '),
                      ),
                  ],
                ),
              // Lista de espera: en su orden; al liberarse un lugar se ofrece así.
              if (enEspera.isNotEmpty)
                _ListaEspera(
                  personas: enEspera,
                  onPromover: gestionar && (libres ?? 0) > 0 && !lista.empezo
                      ? _promover
                      : null,
                ),
              // Ya empezada la clase (lo dice el servidor): quien sigue sin registro
              // queda como «no vino».
              if (marcar &&
                  lista.empezo &&
                  enSala.any(
                    (a) => a.estado == 'confirmada' && a.asistencia == null,
                  ))
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: OutlinedButton(
                    onPressed: _terminarLista,
                    child: const Text('Terminar lista'),
                  ),
                ),
            ],
          );
        },
      ),
    );
  }

  Widget _fila(
    Asistente a, {
    required bool abierta,
    required bool marcar,
    required bool gestionar,
  }) {
    // Sin permiso de marcar asistencia, la lista solo se consulta; y solo se marca a
    // quien tiene su lugar confirmado, desde que se abre la lista.
    final puedeMarcar = marcar && a.marcable && abierta;
    final ocupado = _marcando.contains(a.reservaId);
    return Card(
      elevation: 0,
      color: context.colores.superficie,
      margin: const EdgeInsets.only(bottom: 6),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: a.llego
            ? BorderSide.none
            : BorderSide(
                color: context.colores.tinta(const Color(0xFFFEC84B)),
                width: 1.5,
              ),
      ),
      child: ListTile(
        title: Text(
          a.nombre,
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        subtitle: Wrap(
          spacing: 6,
          children: [
            if (a.primeraVez)
              Text(
                'Primera vez',
                style: TextStyle(
                  color: context.colores.tinta(const Color(0xFF7A5200)),
                ),
              ),
            if (a.adeudo)
              Text('Adeudo', style: TextStyle(color: context.colores.error)),
            if (a.estado == 'pendiente_pago')
              Text(
                'Pago pendiente',
                style: TextStyle(
                  color: context.colores.tinta(const Color(0xFF7A5200)),
                ),
              ),
            // Se le ofreció un lugar que aún no acepta.
            if (a.estado == 'ofrecida')
              Text(
                'Lugar ofrecido',
                style: TextStyle(
                  color: context.colores.tinta(const Color(0xFF7A5200)),
                ),
              ),
            if (a.asistencia == 'ausente')
              Text(
                a.automatica ? 'No se presentó (automático)' : 'No vino',
                style: TextStyle(color: context.colores.error),
              ),
            if (a.llego && a.retardo)
              Text(
                'Llegó tarde',
                style: TextStyle(
                  color: context.colores.tinta(const Color(0xFF7A5200)),
                ),
              ),
          ],
        ),
        trailing: a.estado == 'ofrecida' && gestionar
            ? TextButton(
                onPressed: ocupado ? null : () => _aceptar(a),
                child: const Text('Aceptar'),
              )
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Llegó tarde (cuenta como asistencia).
                  if (!(a.llego && a.retardo))
                    IconButton(
                      tooltip: 'Llegó tarde',
                      onPressed: ocupado || !puedeMarcar
                          ? null
                          : () => _marcar(a, 'presente', retardo: true),
                      icon: Icon(
                        Icons.schedule,
                        color: context.colores.tinta(const Color(0xFF7A5200)),
                      ),
                    ),
                  Semantics(
                    button: true,
                    label: a.llego
                        ? 'Quitar llegada de ${a.nombre}'
                        : 'Marcar llegada de ${a.nombre}',
                    child: InkResponse(
                      onTap: ocupado || !puedeMarcar
                          ? null
                          : () => _marcar(a, a.llego ? 'ausente' : 'presente'),
                      radius: 28,
                      child: Container(
                        width: 46,
                        height: 46,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: a.llego
                              ? const Color(0xFF12B76A)
                              : context.colores.superficie,
                          border: a.llego
                              ? null
                              : Border.all(
                                  color: context.colores.borde,
                                  width: 2,
                                ),
                        ),
                        child: ocupado
                            ? const Padding(
                                padding: EdgeInsets.all(12),
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                ),
                              )
                            : Icon(
                                Icons.check,
                                color: a.llego
                                    ? Colors.white
                                    : context.colores.textoSuave,
                              ),
                      ),
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

/// Una sección de la lista con su título y su ayuda (invitados, plataformas,
/// apartados), en el mismo estilo de la lista de espera.
class _Seccion extends StatelessWidget {
  const _Seccion({
    super.key,
    required this.titulo,
    required this.ayuda,
    required this.hijos,
  });

  final String titulo;
  final String ayuda;
  final List<Widget> hijos;

  @override
  Widget build(BuildContext context) => Card(
    elevation: 0,
    color: context.colores.superficie,
    margin: const EdgeInsets.only(top: 12),
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    child: Padding(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(titulo, style: const TextStyle(fontWeight: FontWeight.w700)),
          Text(
            ayuda,
            style: TextStyle(fontSize: 12, color: context.colores.textoSuave),
          ),
          const SizedBox(height: 6),
          ...hijos,
        ],
      ),
    ),
  );
}

/// Un renglón de un invitado, un visitante de plataforma o un apartado: quién, de
/// dónde o qué, cómo va y sus acciones.
class _FilaVisitante extends StatelessWidget {
  const _FilaVisitante({
    super.key,
    required this.nombre,
    this.detalle,
    this.estado,
    this.ocupado = false,
    this.acciones = const [],
  });

  final String nombre;
  final String? detalle;
  final String? estado;
  final bool ocupado;
  final List<Widget> acciones;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                nombre,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            if (ocupado)
              const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            else if (estado != null)
              Text(estado!, style: const TextStyle(fontSize: 13)),
          ],
        ),
        if (detalle != null)
          Text(
            detalle!,
            style: TextStyle(fontSize: 12, color: context.colores.textoSuave),
          ),
        if (acciones.isNotEmpty)
          Wrap(
            spacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: acciones,
          ),
      ],
    ),
  );
}

/// La lista de espera de la clase, en su orden, y «Ofrecer lugares» si hay libres
/// y se pueden gestionar reservas.
class _ListaEspera extends StatelessWidget {
  const _ListaEspera({required this.personas, this.onPromover});

  final List<Asistente> personas;
  final VoidCallback? onPromover;

  @override
  Widget build(BuildContext context) => Card(
    key: const Key('lista-espera'),
    elevation: 0,
    color: context.colores.superficie,
    margin: const EdgeInsets.only(top: 12),
    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    child: Padding(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Lista de espera · ${personas.length}',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              if (onPromover != null)
                TextButton(
                  onPressed: onPromover,
                  child: const Text('Ofrecer lugares'),
                ),
            ],
          ),
          Text(
            'Si se libera un lugar, se ofrece en este orden.',
            style: TextStyle(fontSize: 12, color: context.colores.textoSuave),
          ),
          const SizedBox(height: 6),
          for (final (i, a) in personas.indexed)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Text('${i + 1}. ${a.nombre}'),
            ),
        ],
      ),
    ),
  );
}

/// Agregar a la clase a quien llegó sin reservar (`POST /sesiones/{id}/reservas`,
/// como en la web): se busca, se elige y se reserva con su plan. Si la clase está
/// llena, entra a la lista de espera. Otro toque o una respuesta perdida no la
/// agrega dos veces (clave del intento, ADR 0201). Cierra con su nombre y si quedó
/// en espera.
class AgregarAClaseSheet extends ConsumerStatefulWidget {
  const AgregarAClaseSheet({
    super.key,
    required this.sesionId,
    required this.llena,
    this.terminos = const Terminologia(),
  });

  final String sesionId;
  final bool llena;
  final Terminologia terminos;

  @override
  ConsumerState<AgregarAClaseSheet> createState() => _AgregarAClaseSheetState();
}

class _AgregarAClaseSheetState extends ConsumerState<AgregarAClaseSheet> {
  ClienteAgenda? _persona;
  var _guardando = false;
  String? _error;

  Future<void> _agregar() async {
    final persona = _persona;
    final repo = ref.read(agendaRepositoryProvider);
    if (persona == null || repo == null || _guardando) {
      return;
    }
    setState(() {
      _guardando = true;
      _error = null;
    });
    try {
      await repo.agregarAClase(
        widget.sesionId,
        persona.id,
        esperar: widget.llena,
      );
      if (mounted) {
        Navigator.of(
          context,
        ).pop((nombre: persona.nombre, espera: widget.llena));
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _error = mensajeDeError(e, porDefecto: 'No se pudo agregar.');
          _guardando = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final clase = widget.terminos.sesion.toLowerCase();
    final miembro = widget.terminos.miembro.toLowerCase();
    final persona = _persona;
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
                'Agregar a la $clase',
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'El $miembro que llegó sin reservar. Se reserva con su plan, '
                'como cualquier reserva.',
                style: TextStyle(color: context.colores.textoSuave),
              ),
              const SizedBox(height: 12),
              if (persona == null)
                BuscarPersona(
                  habilitado: !_guardando,
                  onElegir: (c) => setState(() {
                    _persona = c;
                    _error = null;
                  }),
                )
              else
                ListTile(
                  key: const Key('persona-elegida'),
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.person_outline),
                  title: Text(persona.nombre),
                  subtitle: persona.detalle == null
                      ? null
                      : Text(persona.detalle!),
                  trailing: TextButton(
                    onPressed: _guardando
                        ? null
                        : () => setState(() => _persona = null),
                    child: const Text('Cambiar'),
                  ),
                ),
              if (widget.llena)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    'La $clase está llena: entrará a la lista de espera.',
                    style: TextStyle(color: context.colores.aviso),
                  ),
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
                key: const Key('confirmar-agregar'),
                onPressed: persona == null || _guardando ? null : _agregar,
                child: Text(
                  _guardando
                      ? 'Agregando…'
                      : widget.llena
                      ? 'Agregar a la lista de espera'
                      : 'Agregar a la $clase',
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
