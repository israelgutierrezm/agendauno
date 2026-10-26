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
/// Messenger, la más reciente primero, de 30 en 30 («Ver más»). Tocar una la abre.
class BandejaScreen extends ConsumerStatefulWidget {
  const BandejaScreen({super.key});

  @override
  ConsumerState<BandejaScreen> createState() => _BandejaScreenState();
}

class _BandejaScreenState extends ConsumerState<BandejaScreen> {
  var _cargandoMas = false;

  Future<void> _verMas() async {
    if (_cargandoMas) {
      return;
    }
    setState(() => _cargandoMas = true);
    final aviso = ScaffoldMessenger.of(context);
    try {
      await ref.read(conversacionesProvider.notifier).cargarMas();
    } on DioException catch (e) {
      aviso.showSnackBar(
        SnackBar(
          content: Text(
            mensajeDeError(e, porDefecto: 'No se pudieron cargar más.'),
          ),
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _cargandoMas = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
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
      data: (pagina) => RefreshIndicator(
        onRefresh: () => ref.refresh(conversacionesProvider.future),
        child: pagina.conversaciones.isEmpty
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
                itemCount:
                    pagina.conversaciones.length + (pagina.hayMas ? 1 : 0),
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  if (i == pagina.conversaciones.length) {
                    return Padding(
                      padding: const EdgeInsets.all(12),
                      child: OutlinedButton(
                        key: const Key('mas-conversaciones'),
                        onPressed: _cargandoMas ? null : _verMas,
                        child: Text(
                          _cargandoMas ? 'Cargando…' : 'Ver más conversaciones',
                        ),
                      ),
                    );
                  }
                  final c = pagina.conversaciones[i];
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
  bool _cargandoAnteriores = false;

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
          // Los anteriores que ya se cargaron se quedan.
          _conversacion = c.conservando(_conversacion);
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

  /// La tanda de mensajes antes del primero que se ve (como la web).
  Future<void> _cargarAnteriores() async {
    final repo = ref.read(bandejaRepositoryProvider);
    final c = _conversacion;
    final primero = c?.mensajes.firstOrNull;
    if (repo == null || c == null || primero == null || _cargandoAnteriores) {
      return;
    }
    setState(() => _cargandoAnteriores = true);
    final aviso = ScaffoldMessenger.of(context);
    try {
      final tanda = await repo.anteriores(c.id, primero.id);
      final actual = _conversacion;
      // Si mientras tanto cambió lo que se ve, esta tanda ya no va.
      if (mounted &&
          actual != null &&
          actual.id == c.id &&
          actual.mensajes.firstOrNull?.id == primero.id) {
        setState(
          () => _conversacion = actual.conAnteriores(
            tanda.mensajes,
            hayMas: tanda.hayAnteriores,
          ),
        );
      }
    } on DioException catch (e) {
      aviso.showSnackBar(
        SnackBar(
          content: Text(
            mensajeDeError(
              e,
              porDefecto: 'No se pudieron cargar los mensajes anteriores.',
            ),
          ),
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _cargandoAnteriores = false);
      }
    }
  }

  /// Fuera del plazo de Meta, por WhatsApp: una plantilla aprobada.
  Future<void> _plantilla() async {
    final aviso = ScaffoldMessenger.of(context);
    final enviada = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => PlantillaSheet(conversacionId: widget.id),
    );
    if (enviada == true && mounted) {
      aviso.showSnackBar(const SnackBar(content: Text('Plantilla enviada.')));
      await _cargar();
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
                    // Arriba (al final, por ir al revés): los anteriores.
                    itemCount: c.mensajes.length + (c.hayAnteriores ? 1 : 0),
                    itemBuilder: (context, i) => i == c.mensajes.length
                        ? Center(
                            child: TextButton(
                              key: const Key('mensajes-anteriores'),
                              onPressed: _cargandoAnteriores
                                  ? null
                                  : _cargarAnteriores,
                              child: Text(
                                _cargandoAnteriores
                                    ? 'Cargando…'
                                    : 'Cargar mensajes anteriores',
                              ),
                            ),
                          )
                        : _Burbuja(
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
                      : Padding(
                          padding: const EdgeInsets.all(16),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                c.conPlantilla
                                    ? 'Ya pasó el plazo que da Meta para contestar con texto libre. Puedes mandarle una plantilla aprobada o esperar a que vuelva a escribir.'
                                    : 'Ya pasó el plazo que da Meta para contestar. Espera a que vuelva a escribir.',
                                key: const Key('fuera-de-plazo'),
                              ),
                              if (c.conPlantilla)
                                Padding(
                                  padding: const EdgeInsets.only(top: 8),
                                  child: OutlinedButton(
                                    key: const Key('con-plantilla'),
                                    onPressed: _plantilla,
                                    child: const Text('Mandar una plantilla'),
                                  ),
                                ),
                            ],
                          ),
                        ),
                ),
              ],
            ),
    );
  }
}

/// Contestar por WhatsApp fuera de las 24 horas con una plantilla aprobada del
/// número del negocio (como la web): se elige, se llenan sus datos ({{1}}, {{2}}…)
/// y se ve cómo le llegará. Meta la cobra a la cuenta de WhatsApp del negocio.
/// Cierra con `true` al mandarla.
class PlantillaSheet extends ConsumerStatefulWidget {
  const PlantillaSheet({super.key, required this.conversacionId});

  final String conversacionId;

  @override
  ConsumerState<PlantillaSheet> createState() => _PlantillaSheetState();
}

class _PlantillaSheetState extends ConsumerState<PlantillaSheet> {
  List<PlantillaWhatsApp>? _plantillas;
  PlantillaWhatsApp? _elegida;
  var _valores = <TextEditingController>[];
  var _enviando = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _leer();
  }

  @override
  void dispose() {
    for (final v in _valores) {
      v.dispose();
    }
    super.dispose();
  }

  Future<void> _leer() async {
    final repo = ref.read(bandejaRepositoryProvider);
    if (repo == null) {
      return;
    }
    try {
      final lista = await repo.plantillas();
      if (mounted) {
        setState(() => _plantillas = lista);
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _plantillas = const [];
          _error = mensajeDeError(
            e,
            porDefecto: 'No se pudieron leer tus plantillas de WhatsApp.',
          );
        });
      }
    }
  }

  void _elegir(PlantillaWhatsApp? p) {
    for (final v in _valores) {
      v.dispose();
    }
    setState(() {
      _elegida = p;
      _valores = List.generate(
        p?.variables ?? 0,
        (_) => TextEditingController(),
      );
      _error = null;
    });
  }

  List<String> get _datos => [for (final v in _valores) v.text];

  bool get _lista =>
      _elegida != null && _datos.every(PlantillaWhatsApp.datoValido);

  Future<void> _mandar() async {
    final repo = ref.read(bandejaRepositoryProvider);
    final p = _elegida;
    if (repo == null || p == null || !_lista || _enviando) {
      return;
    }
    setState(() {
      _enviando = true;
      _error = null;
    });
    try {
      await repo.mandarPlantilla(widget.conversacionId, p, _datos);
      if (mounted) {
        Navigator.of(context).pop(true);
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _error = mensajeDeError(e, porDefecto: 'No se pudo mandar.');
          _enviando = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final plantillas = _plantillas;
    final p = _elegida;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          0,
          20,
          16 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Mandar una plantilla',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 8),
              if (plantillas == null)
                const Text('Cargando tus plantillas…')
              else if (plantillas.isEmpty)
                Text(
                  _error ??
                      'No tienes plantillas aprobadas que se puedan mandar desde aquí. Créalas en el administrador de WhatsApp de Meta (de texto, sin imagen en el encabezado).',
                  key: const Key('sin-plantillas'),
                )
              else ...[
                DropdownButtonFormField<String>(
                  key: const Key('elegir-plantilla'),
                  isExpanded: true,
                  initialValue: p?.clave,
                  decoration: const InputDecoration(
                    labelText: 'Plantilla aprobada',
                  ),
                  items: [
                    for (final x in plantillas)
                      DropdownMenuItem(
                        value: x.clave,
                        child: Text(
                          '${x.nombre} · ${x.idioma} · ${x.categoriaTexto}',
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: _enviando
                      ? null
                      : (clave) => _elegir(
                          plantillas.where((x) => x.clave == clave).firstOrNull,
                        ),
                ),
                for (final (i, v) in _valores.indexed)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: TextField(
                      key: Key('dato-plantilla-$i'),
                      controller: v,
                      enabled: !_enviando,
                      maxLength: 1000,
                      decoration: InputDecoration(labelText: 'Dato ${i + 1}'),
                      onChanged: (_) => setState(() {}),
                    ),
                  ),
                if (p != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    'Así le llega',
                    style: Theme.of(context).textTheme.labelLarge,
                  ),
                  const SizedBox(height: 4),
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: Theme.of(
                        context,
                      ).colorScheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Text(
                      p.vistaPrevia(_datos),
                      key: const Key('vista-plantilla'),
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    'Meta cobra este mensaje a la cuenta de WhatsApp de tu negocio, según su categoría.',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ],
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Text(
                      _error!,
                      style: TextStyle(
                        color: Theme.of(context).colorScheme.error,
                      ),
                    ),
                  ),
                const SizedBox(height: 12),
                FilledButton(
                  key: const Key('mandar-plantilla'),
                  onPressed: _lista && !_enviando ? _mandar : null,
                  child: Text(_enviando ? 'Mandando…' : 'Mandar plantilla'),
                ),
              ],
            ],
          ),
        ),
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
