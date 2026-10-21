import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../perfil/presentation/boton_mi_perfil.dart';
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import 'abrir_sesion.dart';
import 'nueva_cita_sheet.dart';

const _diasCortos = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
const _meses = [
  'enero',
  'febrero',
  'marzo',
  'abril',
  'mayo',
  'junio',
  'julio',
  'agosto',
  'septiembre',
  'octubre',
  'noviembre',
  'diciembre',
];
const _diasLargos = [
  'Lunes',
  'Martes',
  'Miércoles',
  'Jueves',
  'Viernes',
  'Sábado',
  'Domingo',
];

bool _mismoDiaQue(DateTime a, DateTime b) =>
    a.year == b.year && a.month == b.month && a.day == b.day;

String _hhmm(DateTime d) =>
    '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

/// Agenda del día para el staff. En negocios de CITAS: el día de cada profesional
/// como línea de tiempo con sus citas y estados. En negocios de CLASES: las clases
/// del día con su cupo. Tocar una sesión abre lo de su tipo (hoja de la cita o pase
/// de lista), sea cual sea la vista.
class AgendaScreen extends ConsumerWidget {
  const AgendaScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    final dia = ref.watch(fechaAgendaProvider);
    final estado = ref.watch(agendaProvider);
    // Los días y las horas son los de la sucursal: «hoy» también, cuando ya se sabe
    // su zona (por las sesiones del día).
    final ahora = DateTime.now();
    final hoy =
        estado.value?.hoyEnSucursal(ahora) ??
        sesion?.hoyEnNegocio(ahora) ??
        DateTime(ahora.year, ahora.month, ahora.day);
    final horaSucursal = etiquetaHoraSucursal(
      estado.value?.sesiones ?? const [],
    );
    // Citas: el negocio agenda al cliente (quien gestiona reservas, como en la web).
    final agenda = estado.value;
    final puedeAgendar =
        (sesion?.esCitas ?? false) &&
        (sesion?.puede('reservas.gestionar') ?? false) &&
        (agenda?.profesionales.isNotEmpty ?? false);
    final elegido = ref.watch(profesionalAgendaProvider);

    return Scaffold(
      backgroundColor: context.colores.fondo,
      floatingActionButton: !puedeAgendar
          ? null
          : FloatingActionButton.extended(
              key: const Key('nueva-cita'),
              icon: const Icon(Icons.add),
              label: const Text('Nueva cita'),
              onPressed: () => mostrarNuevaCita(
                context,
                profesionales: agenda!.profesionales,
                dia: dia,
                // Lo que se está viendo: ese profesional y su sucursal.
                profesionalId: profesionalEnVista(
                  agenda,
                  acotado: sesion?.esInstructorAcotado ?? false,
                  elegido: elegido,
                ),
                sucursalId: agenda.sesiones
                    .map((s) => s.sucursalId)
                    .nonNulls
                    .firstOrNull,
              ),
            ),
      appBar: AppBar(
        backgroundColor: context.colores.fondo,
        titleSpacing: 20,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Agenda',
              style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800),
            ),
            Text(
              '${_diasLargos[dia.weekday - 1]} ${dia.day} de ${_meses[dia.month - 1]}',
              style: TextStyle(
                fontSize: 13,
                color: context.colores.textoSuave,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
        toolbarHeight: 72,
        actions: [
          if (!_mismoDiaQue(dia, hoy))
            TextButton(
              onPressed: () => ref.read(fechaAgendaProvider.notifier).hoy(hoy),
              child: const Text('Hoy'),
            ),
          IconButton(
            tooltip: 'Actualizar',
            icon: const Icon(Icons.refresh),
            onPressed: () => ref.invalidate(agendaProvider),
          ),
          const BotonMiPerfil(),
        ],
      ),
      body: Column(
        children: [
          _TiraSemana(dia: dia, hoy: hoy),
          // El teléfono está en otra zona: las horas son las de la sucursal.
          if (horaSucursal != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  horaSucursal,
                  key: const Key('hora-de-la-sucursal'),
                  style: TextStyle(
                    fontSize: 12,
                    color: context.colores.textoSuave,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ),
          Expanded(
            child: estado.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) => ErrorDeCarga(
                mensaje: 'No se pudo cargar la agenda.',
                onReintentar: () => ref.invalidate(agendaProvider),
              ),
              data: (agenda) => (sesion?.esCitas ?? false)
                  ? _AgendaCitas(
                      agenda: agenda,
                      dia: dia,
                      terminologia: sesion!.terminologia,
                    )
                  : _AgendaClases(
                      agenda: agenda,
                      terminologia:
                          sesion?.terminologia ?? const Terminologia(),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Semana del día elegido; toca un día para verlo. Las flechas pasan a la semana
/// anterior o a la siguiente («Hoy», arriba, regresa).
class _TiraSemana extends ConsumerWidget {
  const _TiraSemana({required this.dia, required this.hoy});

  final DateTime dia;

  /// Hoy en la sucursal (o en el teléfono, si aún no se sabe su zona).
  final DateTime hoy;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final lunes = dia.subtract(Duration(days: dia.weekday - 1));
    final fecha = ref.read(fechaAgendaProvider.notifier);
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 0, 4, 8),
      child: Row(
        children: [
          IconButton(
            tooltip: 'Semana anterior',
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.chevron_left),
            onPressed: () => fecha.moverSemanas(-1),
          ),
          for (var i = 0; i < 7; i++)
            Builder(
              builder: (context) {
                final d = lunes.add(Duration(days: i));
                final sel = d.day == dia.day && d.month == dia.month;
                final esHoy =
                    d.day == hoy.day &&
                    d.month == hoy.month &&
                    d.year == hoy.year;
                return Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 2),
                    child: Semantics(
                      button: true,
                      selected: sel,
                      label: '${_diasLargos[i]} ${d.day}',
                      child: InkWell(
                        borderRadius: BorderRadius.circular(14),
                        onTap: () =>
                            ref.read(fechaAgendaProvider.notifier).elegir(d),
                        child: Container(
                          height: 60,
                          decoration: BoxDecoration(
                            color: sel
                                ? context.colores.acento
                                : Colors.transparent,
                            borderRadius: BorderRadius.circular(14),
                            border: esHoy && !sel
                                ? Border.all(
                                    color: context.colores.acento,
                                    width: 1.5,
                                  )
                                : null,
                          ),
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Text(
                                _diasCortos[i],
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700,
                                  color: sel
                                      ? context.colores.acentoContraste
                                            .withValues(alpha: 0.75)
                                      : context.colores.textoSuave,
                                ),
                              ),
                              Text(
                                '${d.day}',
                                style: TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.w800,
                                  color: sel
                                      ? context.colores.acentoContraste
                                      : context.colores.texto,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                );
              },
            ),
          IconButton(
            tooltip: 'Semana siguiente',
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.chevron_right),
            onPressed: () => fecha.moverSemanas(1),
          ),
        ],
      ),
    );
  }
}

// ------------------------------------------------------------------ CITAS

/// El profesional cuyo día se ve en la agenda de citas (null sin equipo). Quien
/// imparte solo recibe sus citas: se le abre su día, sin elegir a otros. Al resto,
/// el que eligió o el primer profesional con citas ese día (no uno vacío por orden).
String? profesionalEnVista(
  AgendaDia agenda, {
  required bool acotado,
  String? elegido,
}) {
  final id =
      (acotado ? null : elegido) ?? agenda.sesiones.firstOrNull?.instructorId;
  return agenda.profesionales.any((p) => p.id == id)
      ? id
      : agenda.profesionales.firstOrNull?.id;
}

class _AgendaCitas extends ConsumerWidget {
  const _AgendaCitas({
    required this.agenda,
    required this.dia,
    required this.terminologia,
  });

  final AgendaDia agenda;
  final DateTime dia;
  final Terminologia terminologia;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (agenda.profesionales.isEmpty) {
      return const _Vacio('Aún no hay profesionales en el equipo.');
    }
    final acotado = ref.watch(sesionProvider)?.esInstructorAcotado ?? false;
    final elegido = profesionalEnVista(
      agenda,
      acotado: acotado,
      elegido: ref.watch(profesionalAgendaProvider),
    );
    final pro = agenda.profesionales.firstWhere((p) => p.id == elegido);
    final citas = agenda.sesiones
        .where((s) => s.instructorId == pro.id)
        .toList();
    // En qué va cada cita lo dice el servidor.
    final enLocal = citas
        .where(
          (s) => const [
            EstadoCita.llego,
            EstadoCita.enServicio,
          ].contains(s.estadoCita),
        )
        .length;
    // Lo que falta de cada cita (con anticipo ya pagado, solo el resto), no su total.
    // Una cita a la que el cliente no vino no se cobra en caja (ADR 0166): no
    // suma, como en la web y en el Inicio.
    final porCobrar = citas
        .where((s) => s.porCobrar && s.estadoCita != EstadoCita.noAsistio)
        .fold<int>(0, (a, s) => a + (s.porCobrarMinor ?? s.montoMinor ?? 0));
    // Los precios son de la moneda del negocio (una sola, ADR 0099).
    final moneda = ref.watch(sesionProvider)?.moneda ?? 'MXN';

    return Column(
      children: [
        if (!acotado)
          SizedBox(
            height: 52,
            child: ListView.separated(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              scrollDirection: Axis.horizontal,
              itemCount: agenda.profesionales.length,
              separatorBuilder: (_, _) => const SizedBox(width: 8),
              itemBuilder: (context, i) {
                final p = agenda.profesionales[i];
                final color = coloresProfesional[i % coloresProfesional.length];
                final sel = p.id == pro.id;
                final n = agenda.sesiones
                    .where((s) => s.instructorId == p.id && s.programada)
                    .length;
                return ChoiceChip(
                  selected: sel,
                  showCheckmark: false,
                  onSelected: (_) =>
                      ref.read(profesionalAgendaProvider.notifier).elegir(p.id),
                  avatar: CircleAvatar(
                    backgroundColor: color,
                    child: Text(
                      p.iniciales,
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        color: Colors.white,
                      ),
                    ),
                  ),
                  label: Text('${p.nombre.split(' ').first} · $n'),
                  labelStyle: const TextStyle(fontWeight: FontWeight.w700),
                  side: BorderSide(
                    color: sel ? color : Colors.transparent,
                    width: 2,
                  ),
                  backgroundColor: context.colores.superficie2,
                  selectedColor: context.colores.superficie,
                );
              },
            ),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          child: _Resumen(
            items: [
              (
                '${citas.where((s) => s.programada).length}',
                '${terminologia.sesiones.toLowerCase()} hoy',
                context.colores.texto,
              ),
              (
                '$enLocal',
                'en el local',
                context.colores.tinta(const Color(0xFF5B21B6)),
              ),
              (
                Formato.dinero(porCobrar, moneda),
                'por cobrar',
                context.colores.tinta(const Color(0xFF7A5200)),
              ),
            ],
          ),
        ),
        Expanded(
          child: _LineaTiempo(citas: citas, dia: dia),
        ),
      ],
    );
  }
}

/// Día del profesional a escala: horas, citas por estado y la línea de "ahora".
class _LineaTiempo extends ConsumerStatefulWidget {
  const _LineaTiempo({required this.citas, required this.dia});

  final List<SesionAgenda> citas;
  final DateTime dia;

  @override
  ConsumerState<_LineaTiempo> createState() => _LineaTiempoState();
}

class _LineaTiempoState extends ConsumerState<_LineaTiempo> {
  static const _pxHora = 84.0;

  /// Cada cuántos minutos se vuelve a pedir el día: el estado de cada cita (llegó,
  /// en servicio, completada) cambia con la hora y lo calcula el servidor.
  static const _refrescarCada = 2;
  final _scroll = ScrollController();
  Timer? _reloj;
  var _minutos = 0;

  @override
  void initState() {
    super.initState();
    _reloj = Timer.periodic(const Duration(minutes: 1), (_) {
      setState(() {});
      if (++_minutos % _refrescarCada == 0) {
        ref.invalidate(agendaProvider);
      }
    });
    WidgetsBinding.instance.addPostFrameCallback((_) => _enfocar());
  }

  @override
  void dispose() {
    _reloj?.cancel();
    _scroll.dispose();
    super.dispose();
  }

  /// El minuto del día en la sucursal (la escala es la de su hora).
  static int _minuto(DateTime pared) => pared.hour * 60 + pared.minute;

  (int, int) get _rango {
    var ini = 8 * 60;
    var fin = 20 * 60;
    for (final s in widget.citas) {
      ini = math.min(ini, _minuto(s.iniciaLocal) ~/ 60 * 60);
      // Si termina al día siguiente (p. ej. 23:30–00:30), llega al final del día.
      final termina = _mismoDia(s.iniciaLocal, s.terminaLocal)
          ? _minuto(s.terminaLocal)
          : 24 * 60;
      fin = math.max(fin, (termina / 60).ceil() * 60);
    }
    return (ini, math.min(fin, 24 * 60));
  }

  double _y(int minuto) => (minuto - _rango.$1) / 60 * _pxHora;

  /// «Ahora» en la hora de la sucursal (la de las citas del día).
  DateTime get _ahora {
    final ahora = DateTime.now();
    return widget.citas.isEmpty ? ahora : widget.citas.first.enSucursal(ahora);
  }

  void _enfocar() {
    if (!_scroll.hasClients) {
      return;
    }
    final ahora = _ahora;
    final destino = _mismoDia(ahora, widget.dia)
        ? _y(_minuto(ahora))
        : (widget.citas.isNotEmpty
              ? _y(_minuto(widget.citas.first.iniciaLocal))
              : 0);
    _scroll.jumpTo(
      math.max(0, math.min(destino - 120, _scroll.position.maxScrollExtent)),
    );
  }

  static bool _mismoDia(DateTime a, DateTime b) =>
      a.year == b.year && a.month == b.month && a.day == b.day;

  @override
  Widget build(BuildContext context) {
    final (ini, fin) = _rango;
    final alto = (fin - ini) / 60 * _pxHora;
    final ahora = _ahora;
    final minutoAhora = _minuto(ahora);
    final sesion = ref.watch(sesionProvider);
    final verAhora =
        _mismoDia(ahora, widget.dia) &&
        minutoAhora >= ini &&
        minutoAhora <= fin;

    return Container(
      decoration: BoxDecoration(
        color: context.colores.superficie,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
      ),
      child: widget.citas.isEmpty
          ? const _Vacio('Sin citas este día.')
          : SingleChildScrollView(
              controller: _scroll,
              // Abajo, espacio para «Nueva cita»: no tapa la última cita del día.
              padding: const EdgeInsets.only(top: 10, bottom: 96),
              child: SizedBox(
                height: alto,
                child: Stack(
                  children: [
                    for (var m = ini; m < fin; m += 60) ...[
                      Positioned(
                        left: 52,
                        right: 0,
                        top: _y(m),
                        child: Divider(height: 1, color: context.colores.borde),
                      ),
                      Positioned(
                        left: 10,
                        top: _y(m) - 8,
                        child: Text(
                          '${m ~/ 60}:00',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: context.colores.textoSuave,
                          ),
                        ),
                      ),
                    ],
                    for (final s in widget.citas) _tarjeta(context, s, sesion),
                    if (verAhora) ...[
                      Positioned(
                        left: 52,
                        right: 0,
                        top: _y(minutoAhora),
                        child: Container(
                          height: 2,
                          color: const Color(0xFFE5484D),
                        ),
                      ),
                      Positioned(
                        left: 4,
                        top: _y(minutoAhora) - 9,
                        child: Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 5,
                            vertical: 1,
                          ),
                          decoration: BoxDecoration(
                            color: const Color(0xFFE5484D),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            _hhmm(ahora),
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 10.5,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
    );
  }

  Widget _tarjeta(BuildContext context, SesionAgenda s, Sesion? sesion) {
    final tono = TonoServicio.de(s.ofertaId).en(context.colores);
    final estado = s.esCita ? s.estadoCita : null;
    final estilo = estado != null
        ? estiloEstado(context.colores, estado)
        : null;
    final top = _y(_minuto(s.iniciaLocal)) + 1;
    final alto = math.max(28.0, s.duracionMin / 60 * _pxHora - 3);
    final tenue =
        !s.programada ||
        estado == EstadoCita.completada ||
        estado == EstadoCita.noAsistio;
    final (titulo, que) = switch (s.tipo) {
      TipoSesion.cita => (s.cita?.cliente ?? 'Sin cliente', s.oferta ?? '—'),
      TipoSesion.clase => (s.oferta ?? '—', cupoTexto(s)),
    };
    final detalle = '${_hhmm(s.iniciaLocal)}–${_hhmm(s.terminaLocal)} · $que';

    return Positioned(
      left: 58,
      right: 12,
      top: top,
      height: alto,
      child: Opacity(
        opacity: tenue ? 0.55 : 1,
        child: Material(
          color: tono.fondo,
          borderRadius: BorderRadius.circular(12),
          child: InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: alTocarSesion(context, s, sesion),
            child: Padding(
              padding: EdgeInsets.symmetric(
                horizontal: 10,
                vertical: alto < 56 ? 3 : 8,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: alto < 56
                    ? MainAxisAlignment.center
                    : MainAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          titulo,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: tono.tinta,
                            fontWeight: FontWeight.w800,
                            fontSize: 13.5,
                          ),
                        ),
                      ),
                      if (estilo != null)
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 7,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: estilo.$1,
                            borderRadius: BorderRadius.circular(99),
                          ),
                          child: Text(
                            estado!.etiqueta,
                            style: TextStyle(
                              color: estilo.$2,
                              fontSize: 10.5,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                    ],
                  ),
                  if (alto >= 36)
                    Text(
                      detalle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: tono.tinta.withValues(alpha: 0.85),
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// "6/10" de una clase (o "6/∞" sin cupo), con el cupo que cuenta el servidor.
String cupoTexto(SesionAgenda s) {
  final clase = s.clase;
  return '${clase?.ocupados ?? s.ocupados}/${clase?.capacidad ?? '∞'}';
}

/// Colores (fondo, tinta) del pago pendiente de una cita (por pagar o por cobrar).
(Color, Color) estiloPago(ColoresAgendaUno c) =>
    c.par(const Color(0xFFFFF1CC), const Color(0xFF7A5200));

/// Colores (fondo, tinta) de cada estado de cita (en oscuro, aclarados).
(Color, Color) estiloEstado(ColoresAgendaUno c, EstadoCita e) {
  final (fondo, tinta) = switch (e) {
    EstadoCita.confirmada => (const Color(0xFFE3F5EB), const Color(0xFF0F6B3E)),
    EstadoCita.apartada => (const Color(0xFFFFF1CC), const Color(0xFF7A5200)),
    EstadoCita.sinRegistrar => (
      const Color(0xFFFFEAD5),
      const Color(0xFF9A3412),
    ),
    EstadoCita.llego => (const Color(0xFFE3EDFF), const Color(0xFF0B4FD1)),
    EstadoCita.enServicio => (const Color(0xFFEDE7FF), const Color(0xFF5B21B6)),
    EstadoCita.completada => (const Color(0xFFEEF0F4), const Color(0xFF475063)),
    EstadoCita.noAsistio => (const Color(0xFFFDE6E6), const Color(0xFFA11B1B)),
    EstadoCita.cancelada => (const Color(0xFFEEF0F4), const Color(0xFF667085)),
  };
  return c.par(fondo, tinta);
}

// ----------------------------------------------------------------- CLASES

class _AgendaClases extends StatelessWidget {
  const _AgendaClases({required this.agenda, required this.terminologia});

  final AgendaDia agenda;
  final Terminologia terminologia;

  @override
  Widget build(BuildContext context) {
    final clases = agenda.sesiones;
    if (clases.isEmpty) {
      return _Vacio(
        'No hay ${terminologia.sesiones.toLowerCase()} programadas este día.',
      );
    }
    final programadas = clases.where((s) => s.programada);
    // Solo las clases tienen cupo y lista de espera (su bloque `clase`).
    final cupos = programadas.map((s) => s.clase).whereType<BloqueClase>();
    final cap = cupos.fold<int>(0, (a, c) => a + (c.capacidad ?? 0));
    final res = cupos.fold<int>(0, (a, c) => a + c.ocupados);
    final espera = cupos.fold<int>(0, (a, c) => a + c.enEspera);
    final ahora = DateTime.now();

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      children: [
        _Resumen(
          items: [
            (
              '${programadas.length}',
              terminologia.sesiones.toLowerCase(),
              context.colores.texto,
            ),
            ('$res/$cap', 'lugares', context.colores.acento),
            (
              '$espera',
              'en espera',
              context.colores.tinta(const Color(0xFF5B21B6)),
            ),
          ],
        ),
        const SizedBox(height: 12),
        for (final s in clases) _TarjetaClase(sesion: s, ahora: ahora),
      ],
    );
  }
}

class _TarjetaClase extends ConsumerWidget {
  const _TarjetaClase({required this.sesion, required this.ahora});

  final SesionAgenda sesion;
  final DateTime ahora;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = sesion;
    final tono = TonoServicio.de(s.ofertaId).en(context.colores);
    final pasada = !s.terminaEn.isAfter(ahora);
    final enCurso =
        !s.iniciaEn.isAfter(ahora) &&
        s.terminaEn.isAfter(ahora) &&
        s.programada;
    // La ocupación que se muestra la decide el servidor (null: sin anillo).
    final ocupacion = s.ocupacion;
    final pct = ocupacion?.porcentaje ?? 0;
    final color = pct >= 90
        ? context.colores.exito
        : (pct >= 40 ? context.colores.acento : context.colores.aviso);
    final libres = s.clase?.libres;
    final enEspera = s.clase?.enEspera ?? 0;
    final estadoCita = s.esCita ? s.estadoCita : null;
    final chip = estadoCita != null
        ? estadoCita.etiqueta
        : !s.programada
        ? 'Cancelada'
        : enCurso
        ? 'En curso'
        : pasada
        ? null
        : enEspera > 0
        ? '$enEspera en espera'
        : libres == 0
        ? 'Llena'
        : (libres != null && libres <= 2 ? 'Quedan $libres' : null);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      // Tocar abre lo de su tipo: el pase de lista de la clase o la hoja de la cita.
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: alTocarSesion(context, s, ref.watch(sesionProvider)),
        child: Opacity(
          opacity: pasada || !s.programada ? 0.55 : 1,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 50,
                child: Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _hhmm(s.iniciaLocal),
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      Text(
                        '${s.duracionMin} min',
                        style: TextStyle(
                          fontSize: 11,
                          color: context.colores.textoSuave,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              Expanded(
                child: Container(
                  padding: const EdgeInsets.fromLTRB(14, 10, 12, 10),
                  decoration: BoxDecoration(
                    color: tono.fondo,
                    borderRadius: BorderRadius.circular(16),
                    border: enCurso
                        ? Border.all(color: const Color(0xFFD92D20), width: 2)
                        : null,
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            if (chip != null)
                              Container(
                                margin: const EdgeInsets.only(bottom: 4),
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 8,
                                  vertical: 2,
                                ),
                                decoration: BoxDecoration(
                                  color: enCurso
                                      ? const Color(0xFFD92D20)
                                      : context.colores.superficie,
                                  borderRadius: BorderRadius.circular(99),
                                ),
                                child: Text(
                                  chip,
                                  style: TextStyle(
                                    fontSize: 10.5,
                                    fontWeight: FontWeight.w800,
                                    color: enCurso ? Colors.white : tono.tinta,
                                  ),
                                ),
                              ),
                            Text(
                              s.esCita
                                  ? (s.cita?.cliente ?? s.oferta ?? '—')
                                  : (s.oferta ?? '—'),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                color: tono.tinta,
                                fontWeight: FontWeight.w800,
                                fontSize: 15,
                              ),
                            ),
                            Text(
                              [
                                if (s.esCita) s.oferta,
                                s.instructor,
                                s.sala,
                                if (s.porCobrar) s.cita!.estadoPago!.etiqueta,
                              ].whereType<String>().join(' · '),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                color: tono.tinta.withValues(alpha: 0.85),
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                      if (ocupacion != null)
                        SizedBox(
                          width: 54,
                          height: 54,
                          child: Stack(
                            alignment: Alignment.center,
                            children: [
                              SizedBox(
                                width: 54,
                                height: 54,
                                child: CircularProgressIndicator(
                                  value: ocupacion.fraccion,
                                  strokeWidth: 6,
                                  strokeCap: StrokeCap.round,
                                  backgroundColor: context.colores.superficie
                                      .withValues(alpha: 0.8),
                                  color: pasada
                                      ? context.colores.textoSuave
                                      : color,
                                ),
                              ),
                              Text(
                                '${ocupacion.ocupados}/${ocupacion.capacidad}',
                                style: TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w800,
                                  color: tono.tinta,
                                ),
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// --------------------------------------------------------------- comunes

class _Resumen extends StatelessWidget {
  const _Resumen({required this.items});

  final List<(String, String, Color)> items;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        color: context.colores.superficie,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          for (final (valor, etiqueta, color) in items)
            Expanded(
              child: Column(
                children: [
                  // Una cantidad larga (p. ej. en pesos colombianos) se achica
                  // en vez de partirse.
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      valor,
                      maxLines: 1,
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w800,
                        color: color,
                      ),
                    ),
                  ),
                  Text(
                    etiqueta,
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: context.colores.textoSuave,
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _Vacio extends StatelessWidget {
  const _Vacio(this.texto);

  final String texto;

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Text(
        texto,
        textAlign: TextAlign.center,
        style: TextStyle(color: context.colores.textoSuave),
      ),
    ),
  );
}
