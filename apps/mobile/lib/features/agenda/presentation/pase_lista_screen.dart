import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/network/mensaje_error.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../application/agenda_controller.dart';
import '../data/agenda_models.dart';
import '../data/agenda_repository.dart';

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

/// Pase de lista de una clase: quién llegó (botón grande por persona), con avisos de
/// primera vez y adeudo. Cada toque registra la asistencia al momento.
class PaseListaScreen extends ConsumerStatefulWidget {
  const PaseListaScreen({super.key, required this.sesion});

  final SesionAgenda sesion;

  @override
  ConsumerState<PaseListaScreen> createState() => _PaseListaScreenState();
}

class _PaseListaScreenState extends ConsumerState<PaseListaScreen> {
  final _marcando = <String>{};

  Future<void> _terminarLista() async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final mensajero = ScaffoldMessenger.of(context);
    try {
      final cuantos = await repo.terminarLista(widget.sesion.id);
      ref.invalidate(rosterProvider(widget.sesion.id));
      ref.invalidate(agendaProvider);
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

  Future<void> _marcar(
    Asistente a,
    String estado, {
    bool retardo = false,
  }) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    // «No vino» puede cobrar la falta (según la política del negocio): se confirma.
    if (estado == 'ausente') {
      final seguir = await showDialog<bool>(
        context: context,
        builder: (contexto) => AlertDialog(
          content: Text(
            '¿Marcar que ${a.nombre} no vino? Según la política del negocio, puede perder el crédito.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(contexto).pop(false),
              child: const Text('Cancelar'),
            ),
            FilledButton(
              onPressed: () => Navigator.of(contexto).pop(true),
              child: const Text('No vino'),
            ),
          ],
        ),
      );
      if (seguir != true || !mounted) {
        return;
      }
    }
    setState(() => _marcando.add(a.reservaId));
    final mensajero = ScaffoldMessenger.of(context);
    try {
      await repo.marcarAsistencia(a.reservaId, estado, retardo: retardo);
      ref.invalidate(rosterProvider(widget.sesion.id));
      ref.invalidate(agendaProvider);
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

  @override
  Widget build(BuildContext context) {
    final s = widget.sesion;
    final tono = TonoServicio.de(s.ofertaId);
    final roster = ref.watch(rosterProvider(s.id));
    String hhmm(DateTime d) =>
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

    return Scaffold(
      backgroundColor: const Color(0xFFF6F7FB),
      appBar: AppBar(
        title: const Text('Pase de lista'),
        backgroundColor: const Color(0xFFF6F7FB),
      ),
      body: roster.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorDeCarga(
          mensaje: 'No se pudo cargar la lista.',
          onReintentar: () => ref.invalidate(rosterProvider(s.id)),
        ),
        data: (lista) {
          final enSala = lista.asistentes.where((a) => a.enSala).toList();
          final llegaron = enSala.where((a) => a.llego).length;
          final abierta = lista.abierta(DateTime.now());
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
                                '${hhmm(s.iniciaEn)}–${hhmm(s.terminaEn)}${s.sala != null ? ' · ${s.sala}' : ''}',
                                style: TextStyle(
                                  color: tono.tinta,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ],
                          ),
                        ),
                        Text(
                          '$llegaron/${enSala.length}',
                          style: TextStyle(
                            color: tono.tinta,
                            fontSize: 26,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        for (final a in enSala)
                          Expanded(
                            child: Container(
                              height: 8,
                              margin: const EdgeInsets.symmetric(
                                horizontal: 1.5,
                              ),
                              decoration: BoxDecoration(
                                color: a.llego
                                    ? const Color(0xFF079455)
                                    : Colors.white.withValues(alpha: 0.85),
                                borderRadius: BorderRadius.circular(99),
                              ),
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              // Antes de la ventana (ADR 0101), la lista aún no se pasa.
              if (!abierta && lista.asistenciaDesde != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    'La lista se abre a las ${hhmm(lista.asistenciaDesde!)}.',
                    style: const TextStyle(color: Color(0xFF5E6B84)),
                  ),
                ),
              if (enSala.isEmpty)
                Padding(
                  padding: const EdgeInsets.all(24),
                  child: Text(
                    'Nadie reservó esta ${(ref.watch(sesionProvider)?.terminologia ?? const Terminologia()).sesion.toLowerCase()} todavía.',
                    textAlign: TextAlign.center,
                  ),
                ),
              for (final a in enSala) _fila(a, abierta: abierta),
              // Ya empezada la clase (lo dice el servidor): quien sigue sin registro
              // queda como «no vino».
              if ((ref.watch(sesionProvider)?.puede('asistencia.marcar') ??
                      false) &&
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

  Widget _fila(Asistente a, {required bool abierta}) {
    // Sin permiso de marcar asistencia, la lista solo se consulta; y solo se marca a
    // quien tiene su lugar confirmado, desde que se abre la lista.
    final puedeMarcar =
        (ref.watch(sesionProvider)?.puede('asistencia.marcar') ?? false) &&
        a.marcable &&
        abierta;
    final ocupado = _marcando.contains(a.reservaId);
    return Card(
      elevation: 0,
      color: Colors.white,
      margin: const EdgeInsets.only(bottom: 6),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: a.llego
            ? BorderSide.none
            : const BorderSide(color: Color(0xFFFEC84B), width: 1.5),
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
              const Text(
                'Primera vez',
                style: TextStyle(color: Color(0xFF7A5200)),
              ),
            if (a.adeudo)
              const Text('Adeudo', style: TextStyle(color: Color(0xFFB42318))),
            if (a.estado == 'pendiente_pago')
              const Text(
                'Pago pendiente',
                style: TextStyle(color: Color(0xFF7A5200)),
              ),
            if (a.asistencia == 'ausente')
              Text(
                a.automatica ? 'No se presentó (automático)' : 'No vino',
                style: const TextStyle(color: Color(0xFFA11B1B)),
              ),
            if (a.llego && a.retardo)
              const Text(
                'Llegó tarde',
                style: TextStyle(color: Color(0xFF7A5200)),
              ),
          ],
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Llegó tarde (cuenta como asistencia).
            if (!(a.llego && a.retardo))
              IconButton(
                tooltip: 'Llegó tarde',
                onPressed: ocupado || !puedeMarcar
                    ? null
                    : () => _marcar(a, 'presente', retardo: true),
                icon: const Icon(Icons.schedule, color: Color(0xFF7A5200)),
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
                    color: a.llego ? const Color(0xFF12B76A) : Colors.white,
                    border: a.llego
                        ? null
                        : Border.all(color: const Color(0xFFC3CAD5), width: 2),
                  ),
                  child: ocupado
                      ? const Padding(
                          padding: EdgeInsets.all(12),
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Icon(
                          Icons.check,
                          color: a.llego
                              ? Colors.white
                              : const Color(0xFFC3CAD5),
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
