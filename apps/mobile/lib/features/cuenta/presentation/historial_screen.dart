import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_widgets.dart';

/// Historial de clases y citas (GET /mi/historial): lo que tomó, faltó o canceló
/// y si cambió de horario, con su reseña junto a cada una (o calificarla mientras
/// esté a tiempo). Se carga por páginas.
class HistorialScreen extends ConsumerStatefulWidget {
  const HistorialScreen({super.key});

  @override
  ConsumerState<HistorialScreen> createState() => _HistorialState();
}

class _HistorialState extends ConsumerState<HistorialScreen> {
  final _items = <ItemHistorial>[];
  int _pagina = 0;
  int _ultima = 1;
  bool _cargando = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _cargar(reiniciar: true);
  }

  Future<void> _cargar({bool reiniciar = false}) async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null || _cargando) {
      return;
    }
    setState(() {
      _cargando = true;
      _error = null;
    });
    try {
      final pagina = await repo.historial(reiniciar ? 1 : _pagina + 1);
      if (!mounted) {
        return;
      }
      setState(() {
        if (reiniciar) {
          _items.clear();
        }
        _items.addAll(pagina.items);
        _pagina = pagina.pagina;
        _ultima = pagina.ultimaPagina;
      });
    } on DioException {
      if (mounted) {
        setState(() => _error = 'No se pudo cargar tu historial.');
      }
    } finally {
      if (mounted) {
        setState(() => _cargando = false);
      }
    }
  }

  Future<void> _calificar(ItemHistorial i) async {
    final calificada = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _CalificarSheet(i),
    );
    if (calificada == true) {
      await _cargar(reiniciar: true);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Historial')),
    body: RefreshIndicator(
      onRefresh: () => _cargar(reiniciar: true),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          if (_error != null)
            Card(
              child: ListTile(
                title: Text(
                  _error!,
                  style: const TextStyle(color: TemaAgendaUno.error),
                ),
                trailing: TextButton(
                  onPressed: () => _cargar(reiniciar: true),
                  child: const Text('Reintentar'),
                ),
              ),
            )
          else if (_items.isEmpty && !_cargando)
            TextoVacio(
              'Aquí verás tus ${(ref.watch(sesionProvider)?.terminologia ?? const Terminologia()).sesiones.toLowerCase()} cuando pasen.',
            ),
          if (_items.isNotEmpty)
            Card(
              child: Column(
                children: [
                  for (final i in _items)
                    _Fila(i, onCalificar: () => _calificar(i)),
                ],
              ),
            ),
          if (_cargando)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_pagina < _ultima && _error == null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: OutlinedButton(
                onPressed: _cargar,
                child: const Text('Ver más'),
              ),
            ),
        ],
      ),
    ),
  );
}

class _Fila extends StatelessWidget {
  const _Fila(this.i, {required this.onCalificar});

  final ItemHistorial i;
  final VoidCallback onCalificar;

  @override
  Widget build(BuildContext context) {
    final detalle = [
      Formato.fechaHora(i.iniciaEn),
      if (i.sucursal != null) i.sucursal!,
      if (i.instructor != null) 'con ${i.instructor}',
    ].join(' · ');
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            i.oferta ?? '—',
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
          Text(
            detalle,
            style: const TextStyle(color: TemaAgendaUno.textoSuave),
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 12,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              EstadoPunto(i.estadoTexto, color: _color(i)),
              if (i.reprogramada)
                const Text(
                  'Cambió de horario',
                  style: TextStyle(color: TemaAgendaUno.textoSuave),
                ),
            ],
          ),
          if (i.calificacion != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                [
                  '★' * i.calificacion!,
                  if (i.comentario != null) i.comentario!,
                ].join('  '),
                style: const TextStyle(color: TemaAgendaUno.textoSuave),
              ),
            )
          else if (i.calificable)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: onCalificar,
                child: const Text('Calificar'),
              ),
            ),
        ],
      ),
    );
  }

  static Color _color(ItemHistorial i) => switch (i.estado) {
    'asistio' => TemaAgendaUno.exito,
    'no_asistio' => TemaAgendaUno.error,
    'sin_pagar' => TemaAgendaUno.aviso,
    'cancelada' when i.canceladaPor != 'cliente' => TemaAgendaUno.aviso,
    _ => TemaAgendaUno.textoSuave,
  };
}

/// Calificar lo que tomó (1 a 5 y un comentario opcional); al enviarla se cierra.
class _CalificarSheet extends ConsumerStatefulWidget {
  const _CalificarSheet(this.i);

  final ItemHistorial i;

  @override
  ConsumerState<_CalificarSheet> createState() => _CalificarSheetState();
}

class _CalificarSheetState extends ConsumerState<_CalificarSheet> {
  int _estrellas = 0;
  final _comentario = TextEditingController();
  bool _enviando = false;

  @override
  void dispose() {
    _comentario.dispose();
    super.dispose();
  }

  Future<void> _enviar() async {
    final nav = Navigator.of(context);
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _enviando = true);
    final texto = _comentario.text.trim();
    try {
      await ref
          .read(cuentaProvider.notifier)
          .calificar(widget.i.id, _estrellas, texto.isEmpty ? null : texto);
      messenger.showSnackBar(
        const SnackBar(content: Text('¡Gracias por tu calificación!')),
      );
      nav.pop(true);
    } on DioException {
      messenger.showSnackBar(
        const SnackBar(content: Text('No se pudo enviar tu calificación.')),
      );
      if (mounted) {
        setState(() => _enviando = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        0,
        20,
        16 + MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            widget.i.oferta ?? '—',
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
          ),
          Row(
            children: [
              for (var n = 1; n <= 5; n++)
                IconButton(
                  tooltip: '$n de 5',
                  onPressed: _enviando
                      ? null
                      : () => setState(() => _estrellas = n),
                  icon: Icon(
                    n <= _estrellas ? Icons.star : Icons.star_border,
                    color: n <= _estrellas
                        ? TemaAgendaUno.aviso
                        : TemaAgendaUno.textoSuave,
                  ),
                ),
            ],
          ),
          TextField(
            controller: _comentario,
            maxLength: 1000,
            decoration: const InputDecoration(
              hintText: '¿Cómo te fue? (opcional)',
            ),
          ),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton(
              onPressed: _enviando || _estrellas == 0 ? null : _enviar,
              child: const Text('Enviar'),
            ),
          ),
        ],
      ),
    ),
  );
}
