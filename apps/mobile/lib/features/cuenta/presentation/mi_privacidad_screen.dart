import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_screen.dart' show hacerConAviso;

/// Privacidad del alumno frente al negocio (derechos ARCO): recibir o no
/// promociones, ver sus datos y pedir la baja de sus datos. Corregir sus datos
/// es en Mi perfil.
class MiPrivacidadScreen extends ConsumerStatefulWidget {
  const MiPrivacidadScreen({super.key});

  @override
  ConsumerState<MiPrivacidadScreen> createState() => _MiPrivacidadScreenState();
}

class _MiPrivacidadScreenState extends ConsumerState<MiPrivacidadScreen> {
  Privacidad? _datos;
  String? _error;
  bool _ocupado = false;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  Future<void> _cargar() async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    try {
      final datos = await repo.privacidad();
      if (mounted) {
        setState(() {
          _datos = datos;
          _error = null;
        });
      }
    } on DioException {
      if (mounted) {
        setState(() => _error = 'No se pudo cargar tu privacidad.');
      }
    }
  }

  Future<void> _conRepo(
    Future<void> Function(CuentaRepository repo) accion,
  ) async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    setState(() => _ocupado = true);
    await hacerConAviso(context, () => accion(repo));
    if (mounted) {
      setState(() => _ocupado = false);
    }
  }

  Future<void> _promociones(bool recibir) => _conRepo((repo) async {
    final datos = await repo.cambiarPromociones(recibir);
    if (mounted) {
      setState(() => _datos = datos);
    }
  });

  Future<void> _verDatos() => _conRepo((repo) async {
    final datos = await repo.misDatos();
    if (!mounted) {
      return;
    }
    final texto = const JsonEncoder.withIndent('  ').convert(datos);
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.8,
          child: Column(
            children: [
              ListTile(
                title: const Text('Mis datos'),
                subtitle: const Text('Todo lo que el negocio tiene de ti'),
                trailing: TextButton(
                  onPressed: () async {
                    await Clipboard.setData(ClipboardData(text: texto));
                    if (context.mounted) {
                      ScaffoldMessenger.of(
                        context,
                      ).showSnackBar(const SnackBar(content: Text('Copiado.')));
                    }
                  },
                  child: const Text('Copiar'),
                ),
              ),
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                  child: SelectableText(
                    texto,
                    style: const TextStyle(
                      fontFamily: 'monospace',
                      fontSize: 12,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  });

  Future<void> _pedirBaja() async {
    final motivo = TextEditingController();
    final confirmado = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Pedir la baja de mis datos'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'El negocio eliminará tus datos personales. Tus compras y pagos se '
              'conservan sin tu nombre, como lo pide la ley. No se puede deshacer.',
            ),
            const SizedBox(height: 12),
            TextField(
              controller: motivo,
              maxLength: 500,
              decoration: const InputDecoration(labelText: 'Motivo (opcional)'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancelar'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Pedir la baja'),
          ),
        ],
      ),
    );
    final texto = motivo.text.trim();
    motivo.dispose();
    if (confirmado != true || !mounted) {
      return;
    }
    await _conRepo((repo) async {
      final datos = await repo.solicitarBaja(texto.isEmpty ? null : texto);
      if (mounted) {
        setState(() => _datos = datos);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final datos = _datos;
    final baja = datos?.baja;
    final bajaEnCurso = baja != null && baja.estado != 'rechazada';

    return Scaffold(
      appBar: AppBar(title: const Text('Privacidad y mis datos')),
      body: _error != null
          ? Center(child: Text(_error!))
          : datos == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
              children: [
                Card(
                  child: SwitchListTile(
                    title: const Text('Recibir promociones'),
                    subtitle: Text(
                      'Correos y mensajes con ofertas del negocio. Los avisos de '
                      'tus ${(ref.watch(sesionProvider)?.terminologia ?? const Terminologia()).sesiones.toLowerCase()} '
                      'y pagos siempre te llegan.',
                    ),
                    value: datos.recibePromociones,
                    onChanged: _ocupado ? null : _promociones,
                  ),
                ),
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.download_outlined),
                    title: const Text('Ver mis datos'),
                    subtitle: const Text('Todo lo que el negocio tiene de ti'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: _ocupado ? null : _verDatos,
                  ),
                ),
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.person_off_outlined),
                    title: const Text('Pedir la baja de mis datos'),
                    subtitle: Text(
                      baja?.estadoTexto ??
                          'El negocio eliminará tus datos personales.',
                      style: TextStyle(
                        color: baja?.estado == 'rechazada'
                            ? TemaAgendaUno.error
                            : baja != null
                            ? TemaAgendaUno.aviso
                            : null,
                      ),
                    ),
                    onTap: _ocupado || bajaEnCurso ? null : _pedirBaja,
                  ),
                ),
              ],
            ),
    );
  }
}
