import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/aviso_sin_sucursal.dart';
import '../../../core/calendario/agregar_calendario.dart';
import '../../../core/calendario/calendario.dart';
import '../../../core/calendario/calendario_vistas.dart';
import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../agenda/data/agenda_models.dart';
import '../../agenda/presentation/abrir_sesion.dart';
import '../../agenda/presentation/agenda_screen.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../../cuenta/presentation/cuenta_widgets.dart';
import '../../cuenta/presentation/tarjeta_principal.dart';
import '../../perfil/presentation/boton_mi_perfil.dart';
import '../../perfil/presentation/perfil_screen.dart';
import '../application/mis_clases_controller.dart';

enum PestanaInstructor { inicio, clases, agenda }

/// Portal de quien imparte (sin rol de dueño ni recepción): su Inicio con su
/// próxima clase o cita y accesos directos, "Mis clases" en lista, día, semana o
/// mes, y la agenda del día de siempre.
class InstructorScreen extends ConsumerStatefulWidget {
  const InstructorScreen({super.key});

  @override
  ConsumerState<InstructorScreen> createState() => _InstructorScreenState();
}

class _InstructorScreenState extends ConsumerState<InstructorScreen> {
  PestanaInstructor _pestana = PestanaInstructor.inicio;
  VistaCal _vista = VistaCal.lista;
  // Cambia cuando un acceso directo pide otra vista: el calendario se rehace.
  int _version = 0;

  void _ir(PestanaInstructor p, {VistaCal? vista}) => setState(() {
    _pestana = p;
    if (vista != null) {
      _vista = vista;
      _version++;
    }
  });

  @override
  Widget build(BuildContext context) {
    final terminos =
        ref.watch(sesionProvider)?.terminologia ?? const Terminologia();

    return Scaffold(
      // La agenda del día trae su propia barra.
      appBar: _pestana == PestanaInstructor.agenda
          ? null
          : AppBar(
              title: Text(
                _pestana == PestanaInstructor.inicio
                    ? 'Inicio'
                    : 'Mis ${terminos.sesiones.toLowerCase()}',
              ),
              actions: [const BotonMiPerfil()],
            ),
      body: switch (_pestana) {
        PestanaInstructor.inicio => _Inicio(onIr: _ir),
        PestanaInstructor.clases => _MisClases(
          key: ValueKey(_version),
          vista: _vista,
        ),
        PestanaInstructor.agenda => const AgendaScreen(),
      },
      bottomNavigationBar: NavigationBar(
        selectedIndex: _pestana.index,
        onDestinationSelected: (i) => _ir(PestanaInstructor.values[i]),
        destinations: [
          const NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'Inicio',
          ),
          NavigationDestination(
            icon: const Icon(Icons.calendar_month_outlined),
            selectedIcon: const Icon(Icons.calendar_month),
            label: 'Mis ${terminos.sesiones.toLowerCase()}',
          ),
          const NavigationDestination(
            icon: Icon(Icons.schedule_outlined),
            selectedIcon: Icon(Icons.schedule),
            label: 'Agenda',
          ),
        ],
      ),
    );
  }
}

/// "6 de 10 lugares ocupados · 2 en lista de espera" o "Con Ana", según el tipo.
String detalleSesion(SesionAgenda s) {
  switch (s.tipo) {
    case TipoSesion.cita:
      return s.cita?.cliente != null ? 'Con ${s.cita!.cliente}' : '';
    case TipoSesion.clase:
      final ocupados = s.clase?.ocupados ?? s.ocupados;
      final capacidad = s.clase?.capacidad;
      final enEspera = s.clase?.enEspera ?? 0;
      return [
        capacidad != null
            ? '$ocupados de $capacidad lugares ocupados'
            : (ocupados == 1 ? '1 inscrito' : '$ocupados inscritos'),
        if (enEspera > 0) '$enEspera en lista de espera',
      ].join(' · ');
  }
}

// ------------------------------------------------------------------ INICIO

class _Inicio extends ConsumerWidget {
  const _Inicio({required this.onIr});

  final void Function(PestanaInstructor, {VistaCal? vista}) onIr;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    final terminos = sesion?.terminologia ?? const Terminologia();
    final clase = terminos.sesion.toLowerCase();
    final clases = terminos.sesiones.toLowerCase();
    final estado = ref.watch(proximasMisClasesProvider);
    final clima = ref.watch(climaEquipoProvider).value;
    final nombre = (sesion?.nombrePila ?? sesion?.nombre ?? '')
        .trim()
        .split(RegExp(r'\s+'))
        .first;
    final estudio = sesion?.estudioNombre;

    return estado.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => Center(
        child: TextButton(
          onPressed: () => ref.invalidate(proximasMisClasesProvider),
          child: const Text('No se pudo cargar. Reintentar'),
        ),
      ),
      data: (sesiones) {
        final ahora = DateTime.now();
        final pendientes = sesiones
            .where((s) => s.terminaEn.isAfter(ahora))
            .toList();
        final proxima = pendientes.firstOrNull;
        final enCurso = proxima != null && !proxima.iniciaEn.isAfter(ahora);
        final deHoy = sesiones
            .where((s) => Calendario.mismoDia(s.iniciaEn, ahora))
            .toList();
        final esperados = deHoy.fold<int>(0, (n, s) => n + s.ocupados);
        String cuantas(int n) =>
            n == 0 ? 'Sin $clases' : (n == 1 ? '1 $clase' : '$n $clases');
        // Lo de hoy que ya empezó y aún pide pasar lista o marcar si llegó.
        final faltan = deHoy.where((s) => s.faltaMarcar(ahora)).toList();
        final listas = faltan.where((s) => !s.esCita).length;
        final llegadas = faltan.length - listas;
        final pendientesHoy = [
          if (listas > 0) '$listas por pasar lista',
          if (llegadas > 0) '$llegadas por marcar llegada',
        ].join(' · ');

        return RefreshIndicator(
          onRefresh: () => ref.refresh(proximasMisClasesProvider.future),
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
                    ? 'Aquí tienes tus $clases.'
                    : 'Aquí tienes tus $clases y citas en $estudio.',
                style: const TextStyle(color: TemaAgendaUno.textoSuave),
              ),
              const SizedBox(height: 16),
              // La tarjeta principal: siempre, con o sin algo agendado.
              TarjetaPrincipal(
                etiqueta: enCurso
                    ? 'En curso'
                    : proxima == null
                    ? 'Lo próximo en tu agenda'
                    : (proxima.esCita
                          ? 'Tu próxima cita'
                          : 'Tu próxima $clase'),
                foto: fotoNegocio(sesion?.perfil),
                clima: clima,
                dondeClima:
                    clima?.dondeTexto(
                      proxima?.esCita == true ? 'cita' : clase,
                    ) ??
                    '',
                contenido: proxima == null
                    ? [
                        const SizedBox(height: 6),
                        const Text(
                          'Nada agendado por ahora',
                          style: TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'No tienes $clases asignadas en los próximos días.',
                          style: const TextStyle(
                            color: TemaAgendaUno.textoSuave,
                          ),
                        ),
                        const SizedBox(height: 14),
                        FilledButton(
                          onPressed: () => onIr(
                            PestanaInstructor.clases,
                            vista: VistaCal.lista,
                          ),
                          child: const Text('Mi calendario'),
                        ),
                      ]
                    : [
                        const SizedBox(height: 6),
                        Text(
                          proxima.oferta ?? '—',
                          style: const TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          [
                            Formato.fechaHora(
                              proxima.iniciaEn.toIso8601String(),
                            ),
                            ?proxima.sucursal,
                            ?proxima.sala,
                          ].join(' · '),
                        ),
                        if (detalleSesion(proxima).isNotEmpty)
                          Text(
                            detalleSesion(proxima),
                            style: TextStyle(
                              color: proxima.enEspera > 0
                                  ? TemaAgendaUno.aviso
                                  : TemaAgendaUno.textoSuave,
                            ),
                          ),
                        const SizedBox(height: 14),
                        Wrap(
                          spacing: 8,
                          runSpacing: 4,
                          children: [
                            FilledButton(
                              onPressed: () => abrirSesion(context, proxima),
                              child: Text(
                                proxima.esCita ? 'Ver cita' : 'Pasar lista',
                              ),
                            ),
                            BotonAgregarCalendario(
                              evento: 'sesion-${proxima.id}',
                              titulo: proxima.oferta ?? terminos.sesion,
                              inicio: proxima.iniciaEn,
                              fin: proxima.terminaEn,
                              lugar: proxima.sucursal,
                            ),
                          ],
                        ),
                      ],
              ),
              const SizedBox(height: 12),
              RejillaAccesos([
                TarjetaAcceso(
                  icono: Icons.today_outlined,
                  titulo: 'Hoy',
                  tono: TonosAcceso.reservar,
                  valor: pendientesHoy.isEmpty
                      ? cuantas(deHoy.length)
                      : '${cuantas(deHoy.length)} · $pendientesHoy',
                  onTap: () =>
                      onIr(PestanaInstructor.clases, vista: VistaCal.dia),
                ),
                TarjetaAcceso(
                  icono: Icons.groups_outlined,
                  titulo: terminos.miembros,
                  tono: TonosAcceso.creditos,
                  valor: esperados == 0
                      ? 'Nadie esperado hoy'
                      : (esperados == 1
                            ? '1 esperado hoy'
                            : '$esperados esperados hoy'),
                  onTap: () =>
                      onIr(PestanaInstructor.clases, vista: VistaCal.dia),
                ),
                TarjetaAcceso(
                  icono: Icons.view_list_outlined,
                  titulo: 'Próximos 7 días',
                  tono: TonosAcceso.reservas,
                  valor: cuantas(sesiones.length),
                  onTap: () =>
                      onIr(PestanaInstructor.clases, vista: VistaCal.lista),
                ),
                TarjetaAcceso(
                  icono: Icons.calendar_month_outlined,
                  titulo: 'Mi calendario',
                  tono: TonosAcceso.pase,
                  valor: 'Lista, día, semana y mes',
                  onTap: () =>
                      onIr(PestanaInstructor.clases, vista: VistaCal.mes),
                ),
                TarjetaAcceso(
                  icono: Icons.schedule_outlined,
                  titulo: 'Agenda',
                  tono: TonosAcceso.pagos,
                  valor: 'Tu día con horarios y pase de lista',
                  onTap: () => onIr(PestanaInstructor.agenda),
                ),
                TarjetaAcceso(
                  icono: Icons.person_outline,
                  titulo: 'Mi perfil',
                  tono: TonosAcceso.expediente,
                  valor: 'Foto, contraseña y calendario del teléfono',
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

// ------------------------------------------------------------ MIS CLASES

class _MisClases extends ConsumerWidget {
  const _MisClases({super.key, required this.vista});

  final VistaCal vista;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final terminos =
        ref.watch(sesionProvider)?.terminologia ?? const Terminologia();
    final clases = terminos.sesiones.toLowerCase();
    final estado = ref.watch(misClasesProvider);
    // Al cambiar de rango se conserva lo anterior mientras llega lo nuevo.
    final sesiones = estado.value ?? const <SesionAgenda>[];
    final eventos = eventosDeClases(sesiones);

    void abrir(EventoCal e) {
      final s = sesiones.where((x) => x.id == e.id).firstOrNull;
      if (s != null) {
        _mostrarDetalle(context, s);
      }
    }

    return RefreshIndicator(
      onRefresh: () => ref.refresh(misClasesProvider.future),
      child: CalendarioVistas(
        vistaInicial: vista,
        eventos: eventos,
        onAbrir: abrir,
        onRango: (desde, hasta) =>
            ref.read(rangoMisClasesProvider.notifier).fijar(desde, hasta),
        encabezado: [
          if (estado.isLoading) const LinearProgressIndicator(minHeight: 2),
          if (estado.hasError && !estado.isLoading)
            const Padding(
              padding: EdgeInsets.only(bottom: 8),
              child: Text(
                'No se pudo cargar. Desliza hacia abajo para reintentar.',
                style: TextStyle(color: TemaAgendaUno.error),
              ),
            ),
        ],
        lista: [
          TituloSeccion('Próximas $clases'),
          if (eventos.isEmpty && !estado.isLoading)
            TextoVacio('No tienes $clases asignadas en los próximos 30 días.')
          else
            Card(
              child: Column(
                children: [
                  for (final e in eventos)
                    FilaEvento(e, onTap: abrir, conFecha: true),
                ],
              ),
            ),
          const Padding(
            padding: EdgeInsets.only(top: 12),
            child: Text(
              'Para ver todo en el calendario del teléfono, conéctalo en Mi perfil.',
              style: TextStyle(color: TemaAgendaUno.textoSuave, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }

  /// Detalle de una clase o cita suya: pase de lista o cita, y agregar a mi
  /// calendario.
  void _mostrarDetalle(BuildContext context, SesionAgenda s) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                s.oferta ?? '—',
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                '${Formato.fechaHora(s.iniciaEn.toIso8601String())}–${Formato.hora(s.terminaEn)}',
              ),
              Text(
                [?s.sucursal, ?s.sala].join(' · '),
                style: const TextStyle(color: TemaAgendaUno.textoSuave),
              ),
              if (detalleSesion(s).isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    detalleSesion(s),
                    style: TextStyle(
                      fontWeight: FontWeight.w600,
                      color: s.enEspera > 0
                          ? TemaAgendaUno.aviso
                          : TemaAgendaUno.texto,
                    ),
                  ),
                ),
              const SizedBox(height: 16),
              Wrap(
                spacing: 8,
                runSpacing: 4,
                children: [
                  FilledButton(
                    onPressed: () {
                      Navigator.pop(ctx);
                      abrirSesion(context, s);
                    },
                    child: Text(s.esCita ? 'Ver cita' : 'Pasar lista'),
                  ),
                  BotonAgregarCalendario(
                    evento: 'sesion-${s.id}',
                    titulo: s.oferta ?? 'Clase',
                    inicio: s.iniciaEn,
                    fin: s.terminaEn,
                    lugar: s.sucursal,
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
