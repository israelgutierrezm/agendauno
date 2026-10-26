import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/archivos/archivos_nativos.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_repository.dart';

/// Pase de entrada del alumno: un QR firmado que vence en minutos. Mientras se
/// muestra se renueva solo cada minuto (una captura de pantalla deja de servir).
/// Si el negocio lo permite, también va a la cartera del teléfono (ADR 0195):
/// Google Wallet en Android y Apple Wallet en iPhone.
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
  bool _googleWallet = false;
  bool _appleWallet = false;
  bool _abriendoWallet = false;

  /// Renueva el pase de la cartera (como la web): el anterior deja de servir.
  Future<void> _renovarWallet() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null || _abriendoWallet) {
      return;
    }
    final seguir = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        title: const Text('Renovar el pase de la cartera'),
        content: const Text(
          'El pase que ya tienes en la cartera dejará de servir; vuelve a '
          'agregarlo después.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(contexto).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(contexto).pop(true),
            child: const Text('Renovarlo'),
          ),
        ],
      ),
    );
    if (seguir != true || !mounted) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _abriendoWallet = true);
    try {
      await repo.renovarPaseWallet();
      messenger.showSnackBar(
        const SnackBar(
          content: Text(
            'Listo: el pase anterior ya no sirve. Vuelve a agregarlo a tu cartera.',
          ),
        ),
      );
    } on DioException {
      messenger.showSnackBar(
        const SnackBar(content: Text('No se pudo renovar tu pase.')),
      );
    } finally {
      if (mounted) {
        setState(() => _abriendoWallet = false);
      }
    }
  }

  @override
  void initState() {
    super.initState();
    _cargar();
    _temporizador = Timer.periodic(_renovar, (_) => _cargar());
    _revisarWallet();
  }

  /// Cada teléfono, su cartera: Google Wallet en Android y Apple Wallet en iPhone.
  Future<void> _revisarWallet() async {
    final repo = ref.read(cuentaRepositoryProvider);
    final plataforma = defaultTargetPlatform;
    final android = plataforma == TargetPlatform.android;
    final iphone = plataforma == TargetPlatform.iOS;
    if (repo == null || (!android && !iphone)) {
      return;
    }
    try {
      final disponible = await repo.walletDisponible();
      if (mounted) {
        setState(() {
          _googleWallet = android && disponible.google;
          _appleWallet = iphone && disponible.apple;
        });
      }
    } on DioException {
      // Sin cartera: el QR de siempre sigue sirviendo.
    }
  }

  /// Baja el pase (`.pkpass`, con su sesión) y el iPhone muestra la hoja de Apple
  /// Wallet para agregarlo.
  Future<void> _agregarAAppleWallet() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _abriendoWallet = true);
    try {
      final pase = await repo.paseAppleWallet();
      final abierto =
          pase.isNotEmpty && await ArchivosNativos.agregarPaseWallet(pase);
      if (!abierto) {
        messenger.showSnackBar(
          const SnackBar(content: Text('No se pudo abrir Apple Wallet.')),
        );
      }
    } on DioException {
      messenger.showSnackBar(
        const SnackBar(content: Text('No se pudo preparar tu pase.')),
      );
    } finally {
      if (mounted) {
        setState(() => _abriendoWallet = false);
      }
    }
  }

  Future<void> _guardarEnGoogleWallet() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    setState(() => _abriendoWallet = true);
    try {
      final url = await repo.urlGoogleWallet();
      final abierto =
          url.isNotEmpty &&
          await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
      if (!abierto && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('No se pudo abrir Google Wallet.')),
        );
      }
    } on DioException {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('No se pudo preparar tu pase.')),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _abriendoWallet = false);
      }
    }
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
      // El código anterior ya venció: no se deja en pantalla (recepción lo rechazaría).
      if (mounted) {
        setState(() {
          _codigo = null;
          _error = 'No se pudo generar tu pase.';
        });
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
            Text(
              'Muéstralo en recepción. Se renueva solo cada minuto.',
              textAlign: TextAlign.center,
              style: TextStyle(color: context.colores.textoSuave),
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
            else if (_error != null) ...[
              Text(_error!, style: TextStyle(color: context.colores.error)),
              TextButton(
                key: const Key('pase-reintentar'),
                onPressed: _cargar,
                child: const Text('Reintentar'),
              ),
            ] else
              const SizedBox(
                height: 240,
                child: Center(child: CircularProgressIndicator()),
              ),
            if (_googleWallet || _appleWallet) ...[
              const SizedBox(height: 16),
              if (_googleWallet)
                OutlinedButton(
                  key: const Key('pase-google-wallet'),
                  onPressed: _abriendoWallet ? null : _guardarEnGoogleWallet,
                  child: const Text('Guardar en Google Wallet'),
                ),
              if (_appleWallet)
                OutlinedButton(
                  key: const Key('pase-apple-wallet'),
                  onPressed: _abriendoWallet ? null : _agregarAAppleWallet,
                  child: Text(
                    _abriendoWallet
                        ? 'Preparando tu pase…'
                        : 'Agregar a Apple Wallet',
                  ),
                ),
              const SizedBox(height: 4),
              Text(
                'El pase de la cartera no cambia cada minuto como este QR. Si '
                'pierdes tu teléfono, renuévalo.',
                textAlign: TextAlign.center,
                style: TextStyle(color: context.colores.textoSuave),
              ),
              TextButton(
                key: const Key('pase-renovar-wallet'),
                onPressed: _abriendoWallet ? null : _renovarWallet,
                child: const Text('Renovar el pase de la cartera'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
