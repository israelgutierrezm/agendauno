import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_screen.dart' show hacerConAviso;

/// "Mis documentos": lo que pide el negocio y cómo va cada uno. El alumno sube una
/// foto del documento; queda en revisión hasta que el equipo lo valida.
class MisDocumentosScreen extends ConsumerStatefulWidget {
  const MisDocumentosScreen({super.key});

  @override
  ConsumerState<MisDocumentosScreen> createState() =>
      _MisDocumentosScreenState();
}

class _MisDocumentosScreenState extends ConsumerState<MisDocumentosScreen> {
  List<RequisitoDocumento>? _requisitos;
  String? _error;
  String? _subiendo;

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
      final requisitos = await repo.misDocumentos();
      if (mounted) {
        setState(() {
          _requisitos = requisitos;
          _error = null;
        });
      }
    } on DioException {
      if (mounted) {
        setState(() => _error = 'No se pudieron cargar tus documentos.');
      }
    }
  }

  Future<void> _subir(RequisitoDocumento r) async {
    final origen = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Tomar foto'),
              onTap: () => Navigator.of(context).pop(ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Elegir de la galería'),
              onTap: () => Navigator.of(context).pop(ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (origen == null || !mounted) {
      return;
    }
    final foto = await ImagePicker().pickImage(
      source: origen,
      maxWidth: 2000,
      imageQuality: 85,
    );
    if (foto == null || !mounted) {
      return;
    }
    final bytes = await foto.readAsBytes();
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null || !mounted) {
      return;
    }

    setState(() => _subiendo = r.tipoId);
    await hacerConAviso(
      context,
      () => repo.subirDocumento(r.tipoId, bytes, foto.name),
      exito: 'Documento enviado. Queda en revisión.',
    );
    await _cargar();
    if (mounted) {
      setState(() => _subiendo = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final requisitos = _requisitos;

    return Scaffold(
      appBar: AppBar(title: const Text('Mis documentos')),
      body: _error != null
          ? Center(child: Text(_error!))
          : requisitos == null
          ? const Center(child: CircularProgressIndicator())
          : requisitos.isEmpty
          ? const Center(child: Text('Este negocio no te pide documentos.'))
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
              children: [
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 8),
                  child: Text(
                    'Lo que subas queda en revisión hasta que el equipo lo valide.',
                    style: TextStyle(color: TemaAgendaUno.textoSuave),
                  ),
                ),
                for (final r in requisitos)
                  Card(
                    child: ListTile(
                      title: Text(
                        r.obligatorio ? '${r.nombre} · obligatorio' : r.nombre,
                      ),
                      subtitle: Text(
                        r.estadoTexto,
                        style: TextStyle(color: r.color),
                      ),
                      trailing: r.estado == 'aprobado'
                          ? const Icon(
                              Icons.check_circle_outline,
                              color: TemaAgendaUno.exito,
                            )
                          : TextButton(
                              onPressed: _subiendo != null
                                  ? null
                                  : () => _subir(r),
                              child: Text(
                                _subiendo == r.tipoId
                                    ? 'Subiendo…'
                                    : r.estado == null
                                    ? 'Subir'
                                    : 'Subir otro',
                              ),
                            ),
                    ),
                  ),
              ],
            ),
    );
  }
}
