import 'package:flutter/material.dart';

import '../formato.dart';
import '../theme/tema_agendauno.dart';
import 'calendario.dart';

/// Calendario personal en lista, día, semana o mes (alumno e instructor). La lista
/// la pone quien lo usa; tocar un evento llama a `onAbrir`; cada cambio de vista o
/// de periodo avisa el rango visible (`onRango`) para cargar esas fechas.
class CalendarioVistas extends StatefulWidget {
  const CalendarioVistas({
    super.key,
    required this.eventos,
    required this.lista,
    required this.onAbrir,
    this.onRango,
    this.vistaInicial = VistaCal.lista,
    this.encabezado = const [],
    this.hoy,
  });

  final List<EventoCal> eventos;

  /// Contenido de la vista Lista.
  final List<Widget> lista;
  final void Function(EventoCal evento) onAbrir;
  final void Function(DateTime desde, DateTime hasta)? onRango;
  final VistaCal vistaInicial;

  /// Lo que va arriba del calendario (avisos, botones de la pantalla).
  final List<Widget> encabezado;

  /// Para pruebas; si no, hoy.
  final DateTime? hoy;

  @override
  State<CalendarioVistas> createState() => _CalendarioVistasState();
}

class _CalendarioVistasState extends State<CalendarioVistas> {
  late VistaCal _vista = widget.vistaInicial;
  late DateTime _fecha = Calendario.dia(_hoy);

  DateTime get _hoy => widget.hoy ?? DateTime.now();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _avisarRango());
  }

  void _avisarRango() {
    if (!mounted || widget.onRango == null) {
      return;
    }
    final r = Calendario.rango(_vista, _fecha, _hoy);
    widget.onRango!(r.desde, r.hasta);
  }

  void _cambiar({VistaCal? vista, DateTime? fecha}) {
    setState(() {
      _vista = vista ?? _vista;
      _fecha = fecha ?? _fecha;
    });
    _avisarRango();
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        ...widget.encabezado,
        SegmentedButton<VistaCal>(
          showSelectedIcon: false,
          segments: [
            for (final v in VistaCal.values)
              ButtonSegment(value: v, label: Text(v.etiqueta)),
          ],
          selected: {_vista},
          onSelectionChanged: (s) => _cambiar(vista: s.first),
        ),
        if (_vista != VistaCal.lista) _navegacion(),
        const SizedBox(height: 8),
        ...switch (_vista) {
          VistaCal.lista => widget.lista,
          VistaCal.dia => _dia(),
          VistaCal.semana => _semana(),
          VistaCal.mes => _mes(),
        },
      ],
    );
  }

  Widget _navegacion() => Padding(
    padding: const EdgeInsets.only(top: 8),
    child: Row(
      children: [
        IconButton(
          tooltip: 'Anterior',
          icon: const Icon(Icons.chevron_left),
          onPressed: () =>
              _cambiar(fecha: Calendario.mover(_vista, _fecha, -1)),
        ),
        TextButton(
          onPressed: () => _cambiar(fecha: Calendario.dia(_hoy)),
          child: const Text('Hoy'),
        ),
        IconButton(
          tooltip: 'Siguiente',
          icon: const Icon(Icons.chevron_right),
          onPressed: () => _cambiar(fecha: Calendario.mover(_vista, _fecha, 1)),
        ),
        const SizedBox(width: 4),
        Expanded(
          child: Text(
            Calendario.etiqueta(_vista, _fecha),
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontWeight: FontWeight.w600,
              color: TemaAgendaUno.textoSuave,
            ),
          ),
        ),
      ],
    ),
  );

  /// "No hay nada en estas fechas" y, si hay algo después, cómo llegar.
  Widget _vacio(DateTime ultimoVisible) {
    final siguiente = Calendario.siguienteConAlgo(
      widget.eventos,
      ultimoVisible,
    );
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 12),
      child: Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          const Text(
            'No hay nada en estas fechas.',
            style: TextStyle(color: TemaAgendaUno.textoSuave),
          ),
          if (siguiente != null)
            TextButton(
              onPressed: () => _cambiar(fecha: siguiente),
              child: const Text('Ver lo siguiente'),
            ),
        ],
      ),
    );
  }

  List<Widget> _dia() {
    final eventos = Calendario.delDia(widget.eventos, _fecha);
    if (eventos.isEmpty) {
      return [_vacio(_fecha)];
    }
    return [
      Card(
        child: Column(
          children: [
            for (final e in eventos) FilaEvento(e, onTap: widget.onAbrir),
          ],
        ),
      ),
    ];
  }

  List<Widget> _semana() {
    final lunes = Calendario.lunesDe(_fecha);
    final dias = [for (var i = 0; i < 7; i++) Calendario.mas(lunes, i)];
    final hayAlgo = dias.any(
      (d) => Calendario.delDia(widget.eventos, d).isNotEmpty,
    );
    return [
      if (!hayAlgo) _vacio(dias.last),
      for (final d in dias)
        _DiaDeSemana(
          dia: d,
          esHoy: Calendario.mismoDia(d, _hoy),
          eventos: Calendario.delDia(widget.eventos, d),
          onDia: () => _cambiar(vista: VistaCal.dia, fecha: d),
          onAbrir: widget.onAbrir,
        ),
    ];
  }

  List<Widget> _mes() {
    final semanas = Calendario.semanasDelMes(_fecha);
    final delSeleccionado = Calendario.delDia(widget.eventos, _fecha);
    return [
      Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(6, 8, 6, 6),
          child: Column(
            children: [
              Row(
                children: [
                  for (final d in Calendario.diasCortos)
                    Expanded(
                      child: Center(
                        child: Text(
                          d,
                          style: const TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                            color: TemaAgendaUno.textoSuave,
                          ),
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 4),
              for (final semana in semanas)
                Row(
                  children: [
                    for (final d in semana)
                      Expanded(
                        child: _CeldaMes(
                          dia: d,
                          delMes: d.month == _fecha.month,
                          esHoy: Calendario.mismoDia(d, _hoy),
                          elegido: Calendario.mismoDia(d, _fecha),
                          eventos: Calendario.delDia(widget.eventos, d),
                          // Tocar un día lo elige: su lista aparece abajo.
                          onTap: () => setState(() => _fecha = d),
                        ),
                      ),
                  ],
                ),
            ],
          ),
        ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(4, 12, 4, 4),
        child: Text(
          Calendario.etiqueta(VistaCal.dia, _fecha),
          style: Theme.of(context).textTheme.titleSmall,
        ),
      ),
      if (delSeleccionado.isEmpty)
        const Padding(
          padding: EdgeInsets.all(4),
          child: Text(
            'Nada este día.',
            style: TextStyle(color: TemaAgendaUno.textoSuave),
          ),
        )
      else
        Card(
          child: Column(
            children: [
              for (final e in delSeleccionado)
                FilaEvento(e, onTap: widget.onAbrir),
            ],
          ),
        ),
    ];
  }
}

Color colorTono(TonoEvento t) => switch (t) {
  TonoEvento.primario => TemaAgendaUno.acento,
  TonoEvento.aviso => TemaAgendaUno.aviso,
  TonoEvento.suave => TemaAgendaUno.textoSuave,
};

/// Un evento en una lista: hora, título con su detalle y su estado.
class FilaEvento extends StatelessWidget {
  const FilaEvento(
    this.e, {
    super.key,
    required this.onTap,
    this.conFecha = false,
  });

  final EventoCal e;
  final void Function(EventoCal) onTap;

  /// En la lista (varios días), la fecha va con la hora.
  final bool conFecha;

  @override
  Widget build(BuildContext context) => ListTile(
    onTap: () => onTap(e),
    leading: conFecha
        ? null
        : SizedBox(
            width: 46,
            child: Text(
              Formato.hora(e.inicio),
              style: const TextStyle(
                fontWeight: FontWeight.w700,
                fontFeatures: [FontFeature.tabularFigures()],
              ),
            ),
          ),
    minLeadingWidth: 0,
    title: Text(
      e.titulo,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: const TextStyle(fontWeight: FontWeight.w600),
    ),
    subtitle: Text(
      [
        if (conFecha) Formato.fechaHora(e.inicio.toIso8601String()),
        if (e.detalle != null && e.detalle!.isNotEmpty) e.detalle!,
      ].join(' · '),
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
    ),
    trailing: e.estado == null || e.estado!.isEmpty
        ? null
        : Text(
            e.estado!,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: colorTono(e.tono),
            ),
          ),
  );
}

class _DiaDeSemana extends StatelessWidget {
  const _DiaDeSemana({
    required this.dia,
    required this.esHoy,
    required this.eventos,
    required this.onDia,
    required this.onAbrir,
  });

  final DateTime dia;
  final bool esHoy;
  final List<EventoCal> eventos;
  final VoidCallback onDia;
  final void Function(EventoCal) onAbrir;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 6),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        InkWell(
          onTap: onDia,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
            child: Text(
              '${Calendario.diasSemana[dia.weekday - 1]} ${dia.day}',
              style: TextStyle(
                fontWeight: FontWeight.w700,
                color: esHoy ? TemaAgendaUno.acento : TemaAgendaUno.texto,
              ),
            ),
          ),
        ),
        if (eventos.isEmpty)
          const Padding(
            padding: EdgeInsets.only(left: 4, bottom: 4),
            child: Text('—', style: TextStyle(color: TemaAgendaUno.textoSuave)),
          )
        else
          for (final e in eventos) ChipEvento(e, onTap: onAbrir),
      ],
    ),
  );
}

/// Un evento compacto (semana): hora y título; lo propio resaltado.
class ChipEvento extends StatelessWidget {
  const ChipEvento(this.e, {super.key, required this.onTap});

  final EventoCal e;
  final void Function(EventoCal) onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 4),
    child: Material(
      color: e.destacado ? TemaAgendaUno.acentoSuave : TemaAgendaUno.superficie,
      borderRadius: BorderRadius.circular(8),
      child: InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: () => onTap(e),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(8),
            border: Border(
              left: BorderSide(
                color: e.destacado ? TemaAgendaUno.acento : TemaAgendaUno.borde,
                width: 3,
              ),
            ),
          ),
          child: Row(
            children: [
              Text(
                Formato.hora(e.inicio),
                style: const TextStyle(
                  color: TemaAgendaUno.textoSuave,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  e.titulo,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontWeight: e.destacado ? FontWeight.w700 : FontWeight.w500,
                  ),
                ),
              ),
              if (e.estado != null && e.estado!.isNotEmpty)
                Text(
                  e.estado!,
                  style: TextStyle(fontSize: 12, color: colorTono(e.tono)),
                ),
            ],
          ),
        ),
      ),
    ),
  );
}

class _CeldaMes extends StatelessWidget {
  const _CeldaMes({
    required this.dia,
    required this.delMes,
    required this.esHoy,
    required this.elegido,
    required this.eventos,
    required this.onTap,
  });

  final DateTime dia;
  final bool delMes;
  final bool esHoy;
  final bool elegido;
  final List<EventoCal> eventos;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final propios = eventos.where((e) => e.destacado).length;
    final otros = eventos.length - propios;
    return Semantics(
      button: true,
      selected: elegido,
      label: '${dia.day}: ${eventos.length} eventos',
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: onTap,
        child: Container(
          height: 50,
          margin: const EdgeInsets.all(1),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            color: elegido ? TemaAgendaUno.acentoSuave : null,
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                width: 26,
                height: 26,
                alignment: Alignment.center,
                decoration: esHoy
                    ? const BoxDecoration(
                        color: TemaAgendaUno.acento,
                        shape: BoxShape.circle,
                      )
                    : null,
                child: Text(
                  '${dia.day}',
                  style: TextStyle(
                    fontWeight: FontWeight.w600,
                    color: esHoy
                        ? Colors.white
                        : (delMes
                              ? TemaAgendaUno.texto
                              : TemaAgendaUno.textoSuave.withValues(
                                  alpha: 0.6,
                                )),
                  ),
                ),
              ),
              const SizedBox(height: 3),
              // Un punto por evento (máx. 3): azul lo propio, gris lo demás.
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  for (var i = 0; i < (propios + otros).clamp(0, 3); i++)
                    Container(
                      width: 5,
                      height: 5,
                      margin: const EdgeInsets.symmetric(horizontal: 1),
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: i < propios
                            ? TemaAgendaUno.acento
                            : TemaAgendaUno.textoSuave.withValues(alpha: 0.5),
                      ),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
