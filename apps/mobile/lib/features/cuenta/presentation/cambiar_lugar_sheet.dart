import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import '../data/lugares_clase.dart';

/// Elegir o cambiar el lugar numerado de su reserva (B06), por ejemplo al llegar
/// desde la lista de espera. Si mientras tanto alguien tomó ese lugar, el servidor lo
/// rechaza: se dice y se vuelven a cargar los libres (nunca se cambia a otro en
/// silencio).
class CambiarLugarSheet extends ConsumerStatefulWidget {
  const CambiarLugarSheet(this.reserva, {super.key});

  final ReservaMiembro reserva;

  @override
  ConsumerState<CambiarLugarSheet> createState() => _CambiarLugarSheetState();
}

class _CambiarLugarSheetState extends ConsumerState<CambiarLugarSheet> {
  LugaresDeClase? _lugares;
  int? _elegido;
  String? _error;
  var _cargando = true;
  var _guardando = false;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    final sesionId = widget.reserva.sesionId;
    if (repo == null || sesionId == null) {
      return;
    }
    setState(() => _cargando = true);
    try {
      final lugares = await repo.lugaresDeClase(sesionId);
      if (mounted) {
        setState(() {
          _lugares = lugares;
          _elegido = lugares?.mio;
        });
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() => _error = mensajeDeError(e));
      }
    } finally {
      if (mounted) {
        setState(() => _cargando = false);
      }
    }
  }

  Future<void> _guardar() async {
    final lugar = _elegido;
    if (lugar == null || _guardando) {
      return;
    }
    final nav = Navigator.of(context);
    final messenger = ScaffoldMessenger.of(context);
    setState(() {
      _guardando = true;
      _error = null;
    });
    try {
      await ref
          .read(cuentaProvider.notifier)
          .cambiarLugar(widget.reserva.id, lugar);
      messenger.showSnackBar(
        SnackBar(content: Text('Listo: tu lugar es el $lugar.')),
      );
      if (nav.mounted) {
        nav.pop();
      }
    } on DioException catch (e) {
      // Alguien lo tomó (u otra cosa cambió): se dice y se ven los libres de nuevo.
      if (mounted) {
        setState(() {
          _error = mensajeDeError(e);
          _elegido = _lugares?.mio;
        });
        await _cargar();
      }
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final lugares = _lugares;
    final mio = lugares?.mio;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              mio == null ? 'Elige tu lugar' : 'Cambiar mi lugar',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 4),
            Text(
              widget.reserva.oferta ?? '',
              style: TextStyle(color: context.colores.textoSuave),
            ),
            const SizedBox(height: 12),
            if (lugares == null && _cargando)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 24),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (lugares == null && _error == null)
              Text(
                'Esta clase no tiene lugares numerados.',
                style: TextStyle(color: context.colores.textoSuave),
              ),
            if (lugares != null) ...[
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  for (var n = 1; n <= lugares.total; n++)
                    Semantics(
                      label: n == mio
                          ? 'Lugar $n, el tuyo'
                          : !lugares.libre(n)
                          ? 'Lugar $n, ocupado'
                          : 'Lugar $n, libre',
                      child: ChoiceChip(
                        key: Key('lugar-$n'),
                        label: Text(
                          '$n',
                          style: lugares.libre(n)
                              ? null
                              : const TextStyle(
                                  decoration: TextDecoration.lineThrough,
                                ),
                        ),
                        selected: _elegido == n,
                        onSelected: !lugares.libre(n) || _guardando
                            ? null
                            : (si) => setState(() => _elegido = si ? n : mio),
                      ),
                    ),
                ],
              ),
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  mio == null
                      ? 'Tachados: ocupados.'
                      : 'Tachados: ocupados. Ahora tienes el $mio.',
                  style: TextStyle(
                    fontSize: 12,
                    color: context.colores.textoSuave,
                  ),
                ),
              ),
            ],
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  _error!,
                  key: const Key('lugar-error'),
                  style: TextStyle(color: context.colores.error),
                ),
              ),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                key: const Key('guardar-lugar'),
                onPressed:
                    _guardando ||
                        lugares == null ||
                        _elegido == null ||
                        _elegido == mio
                    ? null
                    : _guardar,
                child: Text(
                  _guardando
                      ? 'Guardando…'
                      : _elegido == null || _elegido == mio
                      ? 'Elige un lugar libre'
                      : 'Quedarme con el $_elegido',
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
