import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

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
  Widget build(BuildContext context) => Scaffold(
    // La agenda del día trae su propia barra.
    appBar: _pestana == PestanaEquipo.agenda
        ? null
        : AppBar(
            title: const Text('Inicio'),
            actions: [
              IconButton(
                icon: const Icon(Icons.person_outline),
                tooltip: 'Mi perfil',
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(builder: (_) => const PerfilScreen()),
                ),
              ),
            ],
          ),
    body: switch (_pestana) {
      PestanaEquipo.inicio => _InicioNegocio(onIr: _ir),
      PestanaEquipo.agenda => const AgendaScreen(),
    },
    bottomNavigationBar: NavigationBar(
      selectedIndex: _pestana.index,
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

/// El día de hoy: saludo, la tarjeta grande con lo que sigue (o lo que está en
/// curso) y los indicadores, lo pendiente de cobro y de renovación, y accesos.
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

        return RefreshIndicator(
          onRefresh: () => ref.refresh(resumenHoyProvider.future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            children: [
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
                      : (siguiente != null ? 'Lo que sigue hoy' : 'Hoy'),
                  foto: fotoNegocio(sesion?.perfil),
                  clima: clima,
                  dondeClima: clima?.dondeTexto('clase') ?? '',
                  contenido: [
                    const SizedBox(height: 6),
                    Text(
                      siguiente?.nombre ??
                          (agenda.sesiones > 0
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
                          ?siguiente.sucursal,
                          ?siguiente.instructor,
                        ].join(' · '),
                      ),
                    ],
                    const SizedBox(height: 14),
                    _Indicadores(agenda: agenda, clases: terminos.sesiones),
                    const SizedBox(height: 14),
                    FilledButton(
                      onPressed: () => onIr(PestanaEquipo.agenda),
                      child: const Text('Abrir agenda'),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
              ],
              if (hoy != null &&
                  (hoy.cobros != null || hoy.renovaciones != null))
                _Pendientes(hoy: hoy),
              const SizedBox(height: 4),
              RejillaAccesos([
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

/// Los cuatro números del día, dentro de la tarjeta principal.
class _Indicadores extends StatelessWidget {
  const _Indicadores({required this.agenda, required this.clases});

  final AgendaHoy agenda;
  final String clases;

  @override
  Widget build(BuildContext context) {
    Widget dato(String etiqueta, int valor, {bool aviso = false}) => SizedBox(
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
      children: [
        dato(clases, agenda.sesiones),
        dato('Se esperan', agenda.esperados),
        dato('Llegaron', agenda.llegaron),
        dato('Por pasar lista', agenda.sinMarcar, aviso: agenda.sinMarcar > 0),
      ],
    );
  }
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
