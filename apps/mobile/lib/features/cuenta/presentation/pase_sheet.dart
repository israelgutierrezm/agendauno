import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_repository.dart';

/// Pase de entrada del alumno: un QR firmado que vence en minutos. Mientras se
/// muestra se renueva solo cada minuto (una captura de pantalla deja de servir).
class PaseSheet extends ConsumerStatefulWidget {
  const PaseSheet({super.key});

  @override
  ConsumerState<PaseSheet> createState() => _PaseSheetState();
}

class _PaseSheetState extends ConsumerState<PaseSheet> {
  static const _renovar = Duration(minutes: 1);

  String? _codigo;
  String? _error;
  Timer? _temporizador;

  @override
  void initState() {
    super.initState();
    _cargar();
    _temporizador = Timer.periodic(_renovar, (_) => _cargar());
  }

  @override
  void dispose() {
    _temporizador?.cancel();
    super.dispose();
  }

  Future<void> _cargar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    try {
      final codigo = await repo.pase();
      if (mounted) {
        setState(() {
          _codigo = codigo;
          _error = null;
        });
      }
    } on DioException {
      if (mounted) {
        setState(() => _error = 'No se pudo generar tu pase.');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Mi pase de entrada',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            const Text(
              'Muéstralo en recepción. Se renueva solo cada minuto.',
              textAlign: TextAlign.center,
              style: TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            const SizedBox(height: 16),
            if (_codigo != null)
              Container(
                color: Colors.white,
                padding: const EdgeInsets.all(8),
                child: QrImageView(
                  data: _codigo!,
                  size: 240,
                  semanticsLabel: 'Código QR de tu pase de entrada',
                ),
              )
            else if (_error != null)
              Text(_error!, style: const TextStyle(color: TemaAgendaUno.error))
            else
              const SizedBox(
                height: 240,
                child: Center(child: CircularProgressIndicator()),
              ),
          ],
        ),
      ),
    );
  }
}
