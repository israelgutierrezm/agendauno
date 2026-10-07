import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../perfil/presentation/boton_mi_perfil.dart';
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import 'cita_sheet.dart';
import 'pase_lista_screen.dart';

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

String _hhmm(DateTime d) =>
    '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

/// Agenda del día para el staff. En negocios de CITAS: el día de cada profesional
/// como línea de tiempo con sus citas y estados. En negocios de CLASES: las clases
/// del día con su cupo.
class AgendaScreen extends ConsumerWidget {
  const AgendaScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    final dia = ref.watch(fechaAgendaProvider);
    final estado = ref.watch(agendaProvider);

    return Scaffold(
      backgroundColor: const Color(0xFFF6F7FB),
      appBar: AppBar(
        backgroundColor: const Color(0xFFF6F7FB),
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
              style: const TextStyle(
                fontSize: 13,
                color: Color(0xFF596275),
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
        toolbarHeight: 72,
        actions: [
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
          _TiraSemana(dia: dia),
          Expanded(
            child: estado.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (e, _) =>
                  Center(child: Text('No se pudo cargar la agenda: $e')),
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

/// Semana del día elegido; toca un día para verlo.
class _TiraSemana extends ConsumerWidget {
  const _TiraSemana({required this.dia});

  final DateTime dia;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final lunes = dia.subtract(Duration(days: dia.weekday - 1));
    final hoy = DateTime.now();
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
      child: Row(
        children: [
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
                                ? TemaAgendaUno.acento
                                : Colors.transparent,
                            borderRadius: BorderRadius.circular(14),
                            border: esHoy && !sel
                                ? Border.all(
                                    color: TemaAgendaUno.acento,
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
                                      ? Colors.white70
                                      : const Color(0xFF667085),
                                ),
                              ),
                              Text(
                                '${d.day}',
                                style: TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.w800,
                                  color: sel
                                      ? Colors.white
                                      : const Color(0xFF101828),
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
        ],
      ),
    );
  }
}

// ------------------------------------------------------------------ CITAS

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
    final elegido =
        ref.watch(profesionalAgendaProvider) ?? agenda.profesionales.first.id;
    final indice = math.max(
      0,
      agenda.profesionales.indexWhere((p) => p.id == elegido),
    );
    final pro = agenda.profesionales[indice];
    final citas = agenda.sesiones
        .where((s) => s.instructorId == pro.id)
        .toList();
    final ahora = DateTime.now();
    final enLocal = citas
        .where(
          (s) => const [
            EstadoCita.llego,
            EstadoCita.enServicio,
          ].contains(s.estadoCita(ahora)),
        )
        .length;
    final porCobrar = citas
        .where((s) => s.porCobrar)
        .fold<int>(0, (a, s) => a + (s.precioMinor ?? 0));
    // Los precios son de la moneda del negocio (una sola, ADR 0099).
    final moneda = ref.watch(sesionProvider)?.moneda ?? 'MXN';

    return Column(
      children: [
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
                backgroundColor: const Color(0xFFEAEDF3),
                selectedColor: Colors.white,
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
                const Color(0xFF101828),
              ),
              ('$enLocal', 'en el local', const Color(0xFF5B21B6)),
              (
                Formato.dinero(porCobrar, moneda),
                'por cobrar',
                const Color(0xFF7A5200),
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
  final _scroll = ScrollController();
  Timer? _reloj;

  @override
  void initState() {
    super.initState();
    _reloj = Timer.periodic(const Duration(minutes: 1), (_) => setState(() {}));
    WidgetsBinding.instance.addPostFrameCallback((_) => _enfocar());
  }

  @override
  void dispose() {
    _reloj?.cancel();
    _scroll.dispose();
    super.dispose();
  }

  (int, int) get _rango {
    var ini = 8 * 60;
    var fin = 20 * 60;
    for (final s in widget.citas) {
      ini = math.min(
        ini,
        (s.iniciaEn.hour * 60 + s.iniciaEn.minute) ~/ 60 * 60,
      );
      fin = math.max(
        fin,
        ((s.terminaEn.hour * 60 + s.terminaEn.minute) / 60).ceil() * 60,
      );
    }
    return (ini, math.min(fin, 24 * 60));
  }

  double _y(int minuto) => (minuto - _rango.$1) / 60 * _pxHora;

  void _enfocar() {
    if (!_scroll.hasClients) {
      return;
    }
    final ahora = DateTime.now();
    final destino = _mismoDia(ahora, widget.dia)
        ? _y(ahora.hour * 60 + ahora.minute)
        : (widget.citas.isNotEmpty
              ? _y(
                  widget.citas.first.iniciaEn.hour * 60 +
                      widget.citas.first.iniciaEn.minute,
                )
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
    final ahora = DateTime.now();
    final minutoAhora = ahora.hour * 60 + ahora.minute;
    final verAhora =
        _mismoDia(ahora, widget.dia) &&
        minutoAhora >= ini &&
        minutoAhora <= fin;

    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      child: widget.citas.isEmpty
          ? const _Vacio('Sin citas este día.')
          : SingleChildScrollView(
              controller: _scroll,
              padding: const EdgeInsets.only(top: 10, bottom: 24),
              child: SizedBox(
                height: alto,
                child: Stack(
                  children: [
                    for (var m = ini; m < fin; m += 60) ...[
                      Positioned(
                        left: 52,
                        right: 0,
                        top: _y(m),
                        child: const Divider(
                          height: 1,
                          color: Color(0xFFEEF0F5),
                        ),
                      ),
                      Positioned(
                        left: 10,
                        top: _y(m) - 8,
                        child: Text(
                          '${m ~/ 60}:00',
                          style: const TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: Color(0xFF667085),
                          ),
                        ),
                      ),
                    ],
                    for (final s in widget.citas) _tarjeta(context, s, ahora),
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

  Widget _tarjeta(BuildContext context, SesionAgenda s, DateTime ahora) {
    final tono = TonoServicio.de(s.ofertaId);
    final estado = s.esCita ? s.estadoCita(ahora) : null;
    final estilo = estado != null ? estiloEstado(estado) : null;
    final top = _y(s.iniciaEn.hour * 60 + s.iniciaEn.minute) + 1;
    final alto = math.max(28.0, s.duracionMin / 60 * _pxHora - 3);
    final tenue =
        !s.programada ||
        estado == EstadoCita.completada ||
        estado == EstadoCita.noAsistio;
    final titulo = s.esCita
        ? (s.cita?.cliente ?? 'Sin cliente')
        : (s.oferta ?? '—');
    final detalle =
        '${_hhmm(s.iniciaEn)}–${_hhmm(s.terminaEn)} · ${s.esCita ? (s.oferta ?? '—') : '${s.ocupados}/${s.capacidad ?? '∞'}'}';

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
            onTap: s.esCita ? () => mostrarHojaCita(context, s) : null,
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

/// Colores (fondo, tinta) de cada estado de cita.
(Color, Color) estiloEstado(EstadoCita e) => switch (e) {
  EstadoCita.confirmada => (const Color(0xFFE3F5EB), const Color(0xFF0F6B3E)),
  EstadoCita.sinRegistrar => (const Color(0xFFFFEAD5), const Color(0xFF9A3412)),
  EstadoCita.pendientePago => (
    const Color(0xFFFFF1CC),
    const Color(0xFF7A5200),
  ),
  EstadoCita.llego => (const Color(0xFFE3EDFF), const Color(0xFF0B4FD1)),
  EstadoCita.enServicio => (const Color(0xFFEDE7FF), const Color(0xFF5B21B6)),
  EstadoCita.completada => (const Color(0xFFEEF0F4), const Color(0xFF475063)),
  EstadoCita.noAsistio => (const Color(0xFFFDE6E6), const Color(0xFFA11B1B)),
  EstadoCita.cancelada => (const Color(0xFFEEF0F4), const Color(0xFF667085)),
};

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
    final conCupo = programadas.where((s) => !s.esCita);
    final cap = conCupo.fold<int>(0, (a, s) => a + (s.capacidad ?? 0));
    final res = conCupo.fold<int>(0, (a, s) => a + s.ocupados);
    final espera = programadas.fold<int>(0, (a, s) => a + s.enEspera);
    final ahora = DateTime.now();

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      children: [
        _Resumen(
          items: [
            (
              '${programadas.length}',
              terminologia.sesiones.toLowerCase(),
              const Color(0xFF101828),
            ),
            ('$res/$cap', 'lugares', const Color(0xFF0B4FD1)),
            ('$espera', 'en espera', const Color(0xFF5B21B6)),
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
    // El pase de lista muestra quién va: pide ver reservas.
    final verLista = ref.watch(sesionProvider)?.puede('reservas.ver') ?? false;
    final tono = TonoServicio.de(s.ofertaId);
    final pasada = !s.terminaEn.isAfter(ahora);
    final enCurso =
        !s.iniciaEn.isAfter(ahora) &&
        s.terminaEn.isAfter(ahora) &&
        s.programada;
    final pct = s.ocupacion ?? 0;
    final color = pct >= 0.9
        ? const Color(0xFF079455)
        : (pct >= 0.4 ? const Color(0xFF0070FF) : const Color(0xFFDC6803));
    final libres = s.capacidad != null ? s.capacidad! - s.ocupados : null;
    final estadoCita = s.esCita ? s.estadoCita(ahora) : null;
    final chip = estadoCita != null
        ? estadoCita.etiqueta
        : !s.programada
        ? 'Cancelada'
        : enCurso
        ? 'En curso'
        : pasada
        ? null
        : s.enEspera > 0
        ? '${s.enEspera} en espera'
        : libres == 0
        ? 'Llena'
        : (libres != null && libres <= 2 ? 'Quedan $libres' : null);

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      // Tocar la clase abre su pase de lista.
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: s.programada && verLista
            ? () => Navigator.of(context).push(
                MaterialPageRoute<void>(
                  builder: (_) => PaseListaScreen(sesion: s),
                ),
              )
            : null,
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
                        _hhmm(s.iniciaEn),
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      Text(
                        '${s.duracionMin} min',
                        style: const TextStyle(
                          fontSize: 11,
                          color: Color(0xFF667085),
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
                                      : Colors.white,
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
                                if (s.esCita && s.porCobrar) 'Por cobrar',
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
                      if (s.capacidad != null && !s.esCita)
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
                                  value: pct,
                                  strokeWidth: 6,
                                  strokeCap: StrokeCap.round,
                                  backgroundColor: Colors.white.withValues(
                                    alpha: 0.8,
                                  ),
                                  color: pasada
                                      ? const Color(0xFF98A2B3)
                                      : color,
                                ),
                              ),
                              Text(
                                '${s.ocupados}/${s.capacidad}',
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
        color: Colors.white,
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
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: Color(0xFF596275),
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
        style: const TextStyle(color: Color(0xFF596275)),
      ),
    ),
  );
}
