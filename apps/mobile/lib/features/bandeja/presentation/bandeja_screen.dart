import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/error_de_carga.dart';
import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../auth/application/sesion_controller.dart';
import '../data/bandeja_repository.dart';

/// El nombre de cada red, como se escribe.
String nombreDeCanal(String canal) => switch (canal) {
  'whatsapp' => 'WhatsApp',
  'instagram' => 'Instagram',
  'messenger' => 'Messenger',
  _ => canal,
};

/// La bandeja del equipo (ADR 0200): lo que escriben por WhatsApp, Instagram y
/// Messenger, la más reciente primero. Tocar una la abre.
class BandejaScreen extends ConsumerWidget {
  const BandejaScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final estado = ref.watch(conversacionesProvider);
    final desfase = ref.watch(sesionProvider)?.desfaseNegocioMin;
    return estado.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => ErrorDeCarga(
        mensaje: mensajeDeError(
          e,
          porDefecto: 'No se pudieron cargar los mensajes.',
        ),
        onReintentar: () => ref.invalidate(conversacionesProvider),
      ),
      data: (conversaciones) => RefreshIndicator(
        onRefresh: () => ref.refresh(conversacionesProvider.future),
        child: conversaciones.isEmpty
            ? ListView(
                children: const [
                  Padding(
                    padding: EdgeInsets.all(24),
                    child: Text(
                      'Aún no te escriben. Lo que llegue por WhatsApp, Instagram o Messenger aparece aquí.',
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
              )
            : ListView.separated(
                itemCount: conversaciones.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final c = conversaciones[i];
                  return ListTile(
                    key: ValueKey(c.id),
                    title: Text(
                      c.titulo,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: c.noLeidos > 0
                          ? const TextStyle(fontWeight: FontWeight.w600)
                          : null,
                    ),
                    subtitle: Text(
                      '${nombreDeCanal(c.canal)} · ${c.ultimoTexto ?? ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    trailing: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          Formato.fechaHora(
                            c.ultimoMensajeEn,
                            desfaseMin: desfase,
                          ),
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                        if (c.noLeidos > 0)
                          Text(
                            c.noLeidos == 1
                                ? '1 nuevo'
                                : '${c.noLeidos} nuevos',
                            style: Theme.of(context).textTheme.labelSmall,
                          ),
                      ],
                    ),
                    onTap: () async {
                      await Navigator.of(context).push(
                        MaterialPageRoute<void>(
                          builder: (_) => ConversacionScreen(id: c.id),
                        ),
                      );
                      ref.invalidate(conversacionesProvider);
                    },
                  );
                },
              ),
      ),
    );
  }
}

/// Una conversación: sus mensajes y, si Meta aún deja contestar, el cuadro para
/// escribir o mandar una foto.
class ConversacionScreen extends ConsumerStatefulWidget {
  const ConversacionScreen({super.key, required this.id});

  final String id;

  @override
  ConsumerState<ConversacionScreen> createState() => _ConversacionScreenState();
}

class _ConversacionScreenState extends ConsumerState<ConversacionScreen> {
  final _texto = TextEditingController();
  ConversacionBandeja? _conversacion;
  String? _error;
  bool _enviando = false;

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  @override
  void dispose() {
    _texto.dispose();
    super.dispose();
  }

  Future<void> _cargar() async {
    final repo = ref.read(bandejaRepositoryProvider);
    if (repo == null) {
      return;
    }
    try {
      final c = await repo.conversacion(widget.id);
      if (mounted) {
        setState(() {
          _conversacion = c;
          _error = null;
        });
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(
          () => _error = mensajeDeError(
            e,
            porDefecto: 'No se pudo abrir la conversación.',
          ),
        );
      }
    }
  }

  Future<void> _enviar() async {
    final texto = _texto.text.trim();
    final repo = ref.read(bandejaRepositoryProvider);
    if (texto.isEmpty || repo == null || _enviando) {
      return;
    }
    setState(() => _enviando = true);
    final aviso = ScaffoldMessenger.of(context);
    try {
      await repo.contestar(widget.id, texto);
      _texto.clear();
      await _cargar();
    } on DioException catch (e) {
      aviso.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
    } finally {
      if (mounted) {
        setState(() => _enviando = false);
      }
    }
  }

  Future<void> _foto() async {
    final repo = ref.read(bandejaRepositoryProvider);
    if (repo == null || _enviando) {
      return;
    }
    final foto = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      maxWidth: 2000,
      imageQuality: 85,
    );
    if (foto == null || !mounted) {
      return;
    }
    final bytes = await foto.readAsBytes();
    if (!mounted) {
      return;
    }
    setState(() => _enviando = true);
    final aviso = ScaffoldMessenger.of(context);
    try {
      await repo.mandarFoto(widget.id, bytes, foto.name, pie: _texto.text);
      _texto.clear();
      await _cargar();
    } on DioException catch (e) {
      aviso.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
    } finally {
      if (mounted) {
        setState(() => _enviando = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = _conversacion;
    final desfase = ref.watch(sesionProvider)?.desfaseNegocioMin;
    return Scaffold(
      appBar: AppBar(
        title: Text(c?.titulo ?? 'Conversación'),
        bottom: c == null
            ? null
            : PreferredSize(
                preferredSize: const Size.fromHeight(20),
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Text(
                    [nombreDeCanal(c.canal), ?c.telefono].join(' · '),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
              ),
      ),
      body: _error != null
          ? ErrorDeCarga(mensaje: _error!, onReintentar: _cargar)
          : c == null
          ? const Center(child: CircularProgressIndicator())
          : Column(
              children: [
                Expanded(
                  child: ListView.builder(
                    reverse: true,
                    padding: const EdgeInsets.all(12),
                    itemCount: c.mensajes.length,
                    itemBuilder: (context, i) => _Burbuja(
                      mensaje: c.mensajes[c.mensajes.length - 1 - i],
                      desfaseMin: desfase,
                    ),
                  ),
                ),
                const Divider(height: 1),
                SafeArea(
                  top: false,
                  child: c.puedeContestar
                      ? Padding(
                          padding: const EdgeInsets.fromLTRB(8, 6, 8, 8),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                c.comoAgente
                                    ? 'Pasaron 24 horas: contestas como persona del equipo, hasta ${Formato.fechaHora(c.contestarHasta, desfaseMin: desfase)}.'
                                    : 'Puedes contestar hasta ${Formato.fechaHora(c.contestarHasta, desfaseMin: desfase)}.',
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                              Row(
                                children: [
                                  IconButton(
                                    tooltip: 'Mandar una foto',
                                    onPressed: _enviando ? null : _foto,
                                    icon: const Icon(Icons.photo_outlined),
                                  ),
                                  Expanded(
                                    child: TextField(
                                      controller: _texto,
                                      minLines: 1,
                                      maxLines: 4,
                                      textInputAction: TextInputAction.send,
                                      onSubmitted: (_) => _enviar(),
                                      decoration: const InputDecoration(
                                        hintText: 'Escribe tu respuesta',
                                      ),
                                    ),
                                  ),
                                  IconButton(
                                    tooltip: 'Enviar',
                                    onPressed: _enviando ? null : _enviar,
                                    icon: const Icon(Icons.send),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        )
                      : const Padding(
                          padding: EdgeInsets.all(16),
                          child: Text(
                            'Ya pasó el plazo que da Meta para contestar con texto libre. Puedes mandarle una plantilla aprobada desde el panel web, o esperar a que vuelva a escribir.',
                          ),
                        ),
                ),
              ],
            ),
    );
  }
}

class _Burbuja extends ConsumerWidget {
  const _Burbuja({required this.mensaje, required this.desfaseMin});

  final MensajeBandeja mensaje;
  final int? desfaseMin;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final colores = Theme.of(context).colorScheme;
    final propio = !mensaje.entrante;
    final archivo = mensaje.archivo;
    return Align(
      alignment: propio ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(
          maxWidth: MediaQuery.sizeOf(context).width * 0.78,
        ),
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: propio
              ? colores.primaryContainer
              : colores.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (archivo != null)
              archivo.enLinea && archivo.listo
                  ? _Imagen(mensajeId: mensaje.id)
                  : Text(
                      '📎 ${archivo.nombre ?? 'Archivo'}${archivo.listo ? '' : ' (aún se descarga)'}',
                    ),
            if ((mensaje.texto ?? '').isNotEmpty) Text(mensaje.texto!),
            const SizedBox(height: 2),
            Text(
              [
                Formato.fechaHora(mensaje.enviadoEn, desfaseMin: desfaseMin),
                ?mensaje.enviadoPor,
              ].join(' · '),
              style: Theme.of(context).textTheme.labelSmall,
            ),
          ],
        ),
      ),
    );
  }
}

/// Una imagen del mensaje, pedida con la sesión.
class _Imagen extends ConsumerWidget {
  const _Imagen({required this.mensajeId});

  final String mensajeId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final repo = ref.watch(bandejaRepositoryProvider);
    if (repo == null) {
      return const SizedBox.shrink();
    }
    return FutureBuilder<Uint8List>(
      future: repo.archivo(mensajeId),
      builder: (context, foto) => foto.hasData
          ? ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.memory(foto.data!, fit: BoxFit.cover),
            )
          : const SizedBox(
              height: 120,
              child: Center(child: CircularProgressIndicator()),
            ),
    );
  }
}
