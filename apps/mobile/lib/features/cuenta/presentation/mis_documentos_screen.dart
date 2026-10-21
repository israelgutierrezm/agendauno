import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/archivos/archivos_nativos.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'cuenta_screen.dart' show hacerConAviso;
import 'cuenta_widgets.dart';

/// "Mis documentos": lo que pide el negocio y cómo va cada uno. El alumno sube una
/// foto del documento; queda en revisión hasta que el equipo lo valida. Lo que ya
/// subió se puede ver: una foto dentro de la app y un PDF con el visor del teléfono
/// (el archivo es privado: se baja con su sesión).
class MisDocumentosScreen extends ConsumerStatefulWidget {
  const MisDocumentosScreen({super.key});

  @override
  ConsumerState<MisDocumentosScreen> createState() =>
      _MisDocumentosScreenState();
}

class _MisDocumentosScreenState extends ConsumerState<MisDocumentosScreen> {
  List<RequisitoDocumento>? _requisitos;
  List<DocumentoMio> _otros = const [];
  String? _error;
  String? _subiendo;

  /// El documento que se está bajando para verlo.
  String? _abriendo;

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
      final documentos = await repo.misDocumentos();
      if (mounted) {
        setState(() {
          _requisitos = documentos.requisitos;
          _otros = documentos.otros;
          _error = null;
        });
      }
    } on DioException {
      if (mounted) {
        setState(() => _error = 'No se pudieron cargar tus documentos.');
      }
    }
  }

  /// Lo baja con su sesión y lo muestra: la foto aquí; un PDF, con el visor del
  /// teléfono.
  Future<void> _ver(DocumentoMio d) async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null || _abriendo != null) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    final navegador = Navigator.of(context);
    setState(() => _abriendo = d.id);
    ArchivoDescargado? archivo;
    try {
      archivo = await repo.documento(d.id, nombre: d.nombre);
    } on DioException {
      messenger.showSnackBar(
        const SnackBar(content: Text('No se pudo abrir el documento.')),
      );
    } finally {
      if (mounted) {
        setState(() => _abriendo = null);
      }
    }
    final listo = archivo;
    if (listo == null || !mounted) {
      return;
    }
    if (listo.esImagen) {
      await navegador.push(
        MaterialPageRoute<void>(
          builder: (_) =>
              VisorImagenDocumento(titulo: d.nombre, bytes: listo.bytes),
        ),
      );
    } else if (!await ArchivosNativos.abrir(listo, nombre: d.nombre)) {
      messenger.showSnackBar(
        const SnackBar(
          content: Text('No encontramos una app para abrir este documento.'),
        ),
      );
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

  /// «Ver» (si ya subió algo) y «Subir» (si aún no está aprobado).
  Widget _acciones(RequisitoDocumento r) {
    final documento = r.documento;
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 0, 8, 4),
      child: OverflowBar(
        alignment: MainAxisAlignment.end,
        children: [
          if (documento != null)
            TextButton(
              key: Key('ver-documento-${documento.id}'),
              onPressed: _abriendo != null ? null : () => _ver(documento),
              child: Text(_abriendo == documento.id ? 'Abriendo…' : 'Ver'),
            ),
          if (r.estado != 'aprobado')
            TextButton(
              onPressed: _subiendo != null ? null : () => _subir(r),
              child: Text(
                _subiendo == r.tipoId
                    ? 'Subiendo…'
                    : r.estado == null
                    ? 'Subir'
                    : 'Subir otro',
              ),
            ),
        ],
      ),
    );
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
          : requisitos.isEmpty && _otros.isEmpty
          ? const Center(child: Text('Este negocio no te pide documentos.'))
          : ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
              children: [
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Text(
                    'Lo que subas queda en revisión hasta que el equipo lo valide.',
                    style: TextStyle(color: context.colores.textoSuave),
                  ),
                ),
                for (final r in requisitos)
                  Card(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        ListTile(
                          title: Text(
                            r.obligatorio
                                ? '${r.nombre} · obligatorio'
                                : r.nombre,
                          ),
                          subtitle: Text(
                            r.estadoTexto,
                            style: TextStyle(color: r.colorEn(context.colores)),
                          ),
                        ),
                        _acciones(r),
                      ],
                    ),
                  ),
                // Lo que subió sin un requisito (p. ej. se lo pidieron en
                // recepción).
                if (_otros.isNotEmpty) ...[
                  const TituloSeccion('Otros documentos'),
                  for (final d in _otros)
                    Card(
                      child: ListTile(
                        title: Text(d.nombre),
                        subtitle: d.estadoTexto.isEmpty
                            ? null
                            : Text(d.estadoTexto),
                        trailing: TextButton(
                          key: Key('ver-documento-${d.id}'),
                          onPressed: _abriendo != null ? null : () => _ver(d),
                          child: Text(_abriendo == d.id ? 'Abriendo…' : 'Ver'),
                        ),
                      ),
                    ),
                ],
              ],
            ),
    );
  }
}

/// La foto de un documento suyo, a pantalla completa (se puede acercar).
class VisorImagenDocumento extends StatelessWidget {
  const VisorImagenDocumento({
    super.key,
    required this.titulo,
    required this.bytes,
  });

  final String titulo;
  final Uint8List bytes;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(titulo, overflow: TextOverflow.ellipsis)),
    body: InteractiveViewer(
      maxScale: 5,
      child: Center(
        child: Image.memory(
          bytes,
          semanticLabel: titulo,
          errorBuilder: (_, _, _) =>
              const Text('No se pudo mostrar el documento.'),
        ),
      ),
    ),
  );
}
