import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/aviso_sin_sucursal.dart';
import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../agenda/presentation/agenda_screen.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../cuenta/presentation/cuenta_widgets.dart';
import '../../cuenta/presentation/tarjeta_principal.dart';
import '../../perfil/presentation/perfil_screen.dart';
import '../data/inicio_repository.dart';
import '../data/resumen_hoy.dart';
import 'resumen_mes_card.dart';

enum PestanaEquipo { inicio, agenda }

/// La app del equipo del negocio (dueño, administración, recepción): su Inicio con
/// el día de hoy —el mismo estilo de los otros portales— y la agenda del día.
class EquipoScreen extends ConsumerStatefulWidget {
  const EquipoScreen({super.key});

  @override
  ConsumerState<EquipoScreen> createState() => _EquipoScreenState();
}

class _EquipoScreenState extends ConsumerState<EquipoScreen> {
  PestanaEquipo _pestana = PestanaEquipo.inicio;

  void _ir(PestanaEquipo p) => setState(() => _pestana = p);

  @override
  Widget build(BuildContext context) {
    // Un rol propio puede no ver la agenda: entonces solo tiene su Inicio.
    final verAgenda = ref.watch(sesionProvider)?.puede('agenda.ver') ?? false;
    final pestana = verAgenda ? _pestana : PestanaEquipo.inicio;
    return Scaffold(
      // La agenda del día trae su propia barra.
      appBar: pestana == PestanaEquipo.agenda
          ? null
          : AppBar(
              title: const Text('Inicio'),
              actions: [
                IconButton(
                  icon: const Icon(Icons.person_outline),
                  tooltip: 'Mi perfil',
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => const PerfilScreen(),
                    ),
                  ),
                ),
              ],
            ),
      body: switch (pestana) {
        PestanaEquipo.inicio => _InicioNegocio(onIr: _ir),
        PestanaEquipo.agenda => const AgendaScreen(),
      },
      bottomNavigationBar: !verAgenda
          ? null
          : NavigationBar(
              selectedIndex: pestana.index,
              onDestinationSelected: (i) => _ir(PestanaEquipo.values[i]),
              destinations: const [
                NavigationDestination(
                  icon: Icon(Icons.home_outlined),
                  selectedIcon: Icon(Icons.home),
                  label: 'Inicio',
                ),
                NavigationDestination(
                  icon: Icon(Icons.schedule_outlined),
                  selectedIcon: Icon(Icons.schedule),
                  label: 'Agenda',
                ),
              ],
            ),
    );
  }
}

/// El día de hoy: saludo, la tarjeta grande con lo que sigue (o lo que está en
/// curso) y los indicadores, lo pendiente de cobro y de renovación, y accesos.
/// Cada tipo de negocio con sus preguntas (ADR 0091): en citas, quién viene, quién
/// llegó, qué falta por atender y cobrar, y dónde hay espacios libres.
class _InicioNegocio extends ConsumerWidget {
  const _InicioNegocio({required this.onIr});

  final void Function(PestanaEquipo) onIr;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    final terminos = sesion?.terminologia ?? const Terminologia();
    final clases = terminos.sesiones.toLowerCase();
    final estado = ref.watch(resumenHoyProvider);
    final clima = ref.watch(climaNegocioProvider).value;
    final nombre = (sesion?.nombrePila ?? sesion?.nombre ?? '')
        .trim()
        .split(RegExp(r'\s+'))
        .first;
    final estudio = sesion?.estudioNombre;
    // Cada acceso con su permiso: ver la agenda y, para pasar lista, ver quién va
    // y marcar asistencia.
    final verAgenda = sesion?.puede('agenda.ver') ?? false;
    final pasarLista =
        verAgenda &&
        (sesion?.puede('reservas.ver') ?? false) &&
        (sesion?.puede('asistencia.marcar') ?? false);

    return estado.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => Center(
        child: TextButton(
          onPressed: () => ref.invalidate(resumenHoyProvider),
          child: const Text('No se pudo cargar. Reintentar'),
        ),
      ),
      data: (hoy) {
        final agenda = hoy?.agenda;
        final siguiente = agenda?.siguiente;
        final enCurso = agenda?.enCurso != null;
        final esCitas = hoy?.modalidad == null
            ? (sesion?.esCitas ?? false)
            : hoy!.esCitas;
        final libres = hoy?.libres;

        return RefreshIndicator(
          onRefresh: () {
            ref.invalidate(resumenMesProvider);
            return ref.refresh(resumenHoyProvider.future);
          },
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            children: [
              // Sin sucursal (con varias en el negocio): no ve nada aún.
              if (sesion?.sinSucursal ?? false) const AvisoSinSucursal(),
              Text(
                nombre.isEmpty ? '¡Hola!' : '¡Hola, $nombre!',
                style: const TextStyle(
                  fontSize: 24,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                estudio == null || estudio.isEmpty
                    ? 'Esto es lo que pasa hoy.'
                    : 'Esto es lo que pasa hoy en $estudio.',
                style: const TextStyle(color: TemaAgendaUno.textoSuave),
              ),
              const SizedBox(height: 16),
              if (agenda != null) ...[
                TarjetaPrincipal(
                  etiqueta: enCurso
                      ? 'En curso'
                      : siguiente == null
                      ? 'Hoy'
                      : (esCitas ? 'Quién viene después' : 'Lo que sigue hoy'),
                  foto: fotoNegocio(sesion?.perfil),
                  clima: clima,
                  dondeClima:
                      clima?.dondeTexto(esCitas ? 'cita' : 'clase') ?? '',
                  contenido: [
                    const SizedBox(height: 6),
                    Text(
                      siguiente != null
                          // En citas importa quién viene; el servicio va debajo.
                          ? (esCitas && siguiente.cliente != null
                                ? siguiente.cliente!
                                : siguiente.nombre)
                          : esCitas
                          ? (agenda.sesiones > 0
                                ? 'Terminaron las citas de hoy'
                                : 'No hay citas hoy')
                          : (agenda.sesiones > 0
                                ? 'Terminaron las $clases de hoy'
                                : 'Sin $clases hoy'),
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    if (siguiente != null) ...[
                      const SizedBox(height: 4),
                      Text(
                        [
                          Formato.hora(siguiente.iniciaEn),
                          if (esCitas) ?siguiente.oferta,
                          if (esCitas) ?siguiente.instructor,
                          ?siguiente.sucursal,
                          if (!esCitas) ?siguiente.instructor,
                        ].join(' · '),
                      ),
                    ],
                    const SizedBox(height: 14),
                    _Indicadores(
                      agenda: agenda,
                      clases: terminos.sesiones,
                      esCitas: esCitas,
                    ),
                    const SizedBox(height: 14),
                    FilledButton(
                      onPressed: () => onIr(PestanaEquipo.agenda),
                      child: const Text('Abrir agenda'),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
              ],
              if (libres != null) ...[
                _Libres(
                  libres: libres,
                  onAgenda: verAgenda ? () => onIr(PestanaEquipo.agenda) : null,
                ),
                const SizedBox(height: 12),
              ],
              if (hoy != null &&
                  (hoy.cobros != null || hoy.renovaciones != null))
                _Pendientes(hoy: hoy),
              // Los números del mes, para quien los ve (ADR 0081).
              ...switch (ref.watch(resumenMesProvider)) {
                AsyncData(:final value?) => [
                  ResumenMesCard(
                    mes: value,
                    clientes: terminos.miembros,
                    hoy: DateTime.now(),
                  ),
                  const SizedBox(height: 12),
                ],
                _ => const <Widget>[],
              },
              const SizedBox(height: 4),
              RejillaAccesos([
                if (verAgenda)
                  TarjetaAcceso(
                    icono: Icons.schedule_outlined,
                    titulo: 'Agenda del día',
                    valor: agenda == null
                        ? 'Horarios y pase de lista'
                        : (agenda.sesiones == 1
                              ? '1 ${terminos.sesion.toLowerCase()} hoy'
                              : '${agenda.sesiones} $clases hoy'),
                    tono: TonosAcceso.reservar,
                    onTap: () => onIr(PestanaEquipo.agenda),
                  ),
                if (pasarLista)
                  TarjetaAcceso(
                    icono: Icons.fact_check_outlined,
                    titulo: 'Pasar lista',
                    valor: (agenda?.sinMarcar ?? 0) == 0
                        ? 'Nadie pendiente'
                        : (agenda!.sinMarcar == 1
                              ? '1 persona pendiente'
                              : '${agenda.sinMarcar} personas pendientes'),
                    atencion: (agenda?.sinMarcar ?? 0) > 0,
                    tono: TonosAcceso.creditos,
                    onTap: () => onIr(PestanaEquipo.agenda),
                  ),
                TarjetaAcceso(
                  icono: Icons.person_outline,
                  titulo: 'Mi perfil',
                  valor: 'Foto, contraseña y calendario del teléfono',
                  tono: TonosAcceso.expediente,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => const PerfilScreen(),
                    ),
                  ),
                ),
              ]),
            ],
          ),
        );
      },
    );
  }
}

/// Los cuatro números del día, dentro de la tarjeta principal: los de cada tipo
/// de negocio, no el mismo indicador con otro nombre.
class _Indicadores extends StatelessWidget {
  const _Indicadores({
    required this.agenda,
    required this.clases,
    required this.esCitas,
  });

  final AgendaHoy agenda;
  final String clases;
  final bool esCitas;

  @override
  Widget build(BuildContext context) {
    Widget dato(String etiqueta, Object valor, {bool aviso = false}) =>
        SizedBox(
          width: 120,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                etiqueta,
                style: const TextStyle(
                  fontSize: 12,
                  color: TemaAgendaUno.textoSuave,
                ),
              ),
              Text(
                '$valor',
                style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                  color: aviso ? TemaAgendaUno.aviso : null,
                ),
              ),
            ],
          ),
        );
    return Wrap(
      spacing: 12,
      runSpacing: 10,
      children: esCitas
          ? [
              dato('Citas hoy', agenda.sesiones),
              dato('Ya llegaron', agenda.llegaron),
              dato('Por atender', agenda.porAtender),
              dato('Por cobrar', agenda.porCobrar, aviso: agenda.porCobrar > 0),
            ]
          : [
              dato('$clases hoy', agenda.sesiones),
              dato(
                'Lugares ocupados',
                agenda.capacidad > 0
                    ? '${agenda.esperados} de ${agenda.capacidad}'
                    : agenda.esperados,
              ),
              dato(
                'Listas por registrar',
                agenda.listasPendientes,
                aviso: agenda.listasPendientes > 0,
              ),
              dato('En lista de espera', agenda.enEspera),
            ],
    );
  }
}

/// Citas: quién tiene espacios libres hoy y desde qué hora (el mismo cálculo que
/// al agendar).
class _Libres extends StatelessWidget {
  const _Libres({required this.libres, this.onAgenda});

  final List<LibreHoy> libres;
  final VoidCallback? onAgenda;

  @override
  Widget build(BuildContext context) => Card(
    key: const Key('libres'),
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Espacios libres hoy',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
              if (onAgenda != null)
                TextButton(onPressed: onAgenda, child: const Text('Agendar')),
            ],
          ),
          if (libres.isEmpty)
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: Text(
                'Hoy nadie tiene horario de atención.',
                style: TextStyle(color: TemaAgendaUno.textoSuave),
              ),
            ),
          for (final l in libres)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          l.profesional,
                          style: const TextStyle(fontWeight: FontWeight.w500),
                        ),
                        if (l.sucursal != null)
                          Text(
                            l.sucursal!,
                            style: const TextStyle(
                              color: TemaAgendaUno.textoSuave,
                            ),
                          ),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        l.huecos == 0
                            ? 'Sin espacios'
                            : (l.huecos == 1
                                  ? '1 espacio'
                                  : '${l.huecos} espacios'),
                      ),
                      if (l.huecos > 0 && l.siguiente != null)
                        Text(
                          'desde las ${Formato.hora(l.siguiente!)}',
                          style: const TextStyle(
                            color: TemaAgendaUno.textoSuave,
                          ),
                        ),
                    ],
                  ),
                ],
              ),
            ),
        ],
      ),
    ),
  );
}

/// Lo pendiente de cobro y de renovación (se atiende desde la web).
class _Pendientes extends StatelessWidget {
  const _Pendientes({required this.hoy});

  final ResumenHoy hoy;

  @override
  Widget build(BuildContext context) {
    final c = hoy.cobros;
    final r = hoy.renovaciones;
    Widget linea(String texto, {bool aviso = false}) => Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Text(
        texto,
        style: TextStyle(color: aviso ? TemaAgendaUno.aviso : null),
      ),
    );
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Pendientes',
              style: TextStyle(fontWeight: FontWeight.w700),
            ),
            if (!hoy.hayPendientes)
              linea('Todo al día: nada por cobrar ni renovaciones por atender.')
            else ...[
              if (c != null && c.ordenesPendientes > 0)
                linea(
                  '${c.ordenesPendientes == 1 ? '1 orden' : '${c.ordenesPendientes} órdenes'} por cobrar · ${Formato.dinero(c.porCobrarMinor)}',
                ),
              if (c != null && c.enMora > 0)
                linea(
                  c.enMora == 1
                      ? '1 cuenta en mora'
                      : '${c.enMora} cuentas en mora',
                  aviso: true,
                ),
              if (r != null && r.porVencer > 0)
                linea(
                  r.porVencer == 1
                      ? '1 membresía vence en ${r.dias} días o menos'
                      : '${r.porVencer} membresías vencen en ${r.dias} días o menos',
                ),
              if (r != null && r.vencidas > 0)
                linea(
                  r.vencidas == 1
                      ? '1 membresía vencida por recuperar'
                      : '${r.vencidas} membresías vencidas por recuperar',
                  aviso: true,
                ),
            ],
          ],
        ),
      ),
    );
  }
}
