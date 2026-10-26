import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../../core/network/mensaje_error.dart';
import '../data/directorio.dart';

/// Buscar el negocio por su nombre en el directorio de esta app (como la web): sin
/// escribir nada, los primeros; al escribir, los que coinciden. Cierra con el que
/// se tocó.
Future<NegocioDirectorio?> buscarNegocio(BuildContext context) =>
    showModalBottomSheet<NegocioDirectorio>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => const BuscarNegocioSheet(),
    );

class BuscarNegocioSheet extends ConsumerStatefulWidget {
  const BuscarNegocioSheet({super.key});

  @override
  ConsumerState<BuscarNegocioSheet> createState() => _BuscarNegocioSheetState();
}

class _BuscarNegocioSheetState extends ConsumerState<BuscarNegocioSheet> {
  final _texto = TextEditingController();
  Timer? _espera;

  /// Solo cuenta la respuesta de la última búsqueda.
  var _pedido = 0;
  var _buscando = true;
  List<NegocioDirectorio> _resultados = const [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _buscar('');
  }

  @override
  void dispose() {
    _espera?.cancel();
    _texto.dispose();
    super.dispose();
  }

  void _alEscribir(String texto) {
    _espera?.cancel();
    setState(() => _buscando = true);
    _espera = Timer(
      const Duration(milliseconds: 300),
      () => _buscar(texto.trim()),
    );
  }

  Future<void> _buscar(String q) async {
    final pedido = ++_pedido;
    try {
      final encontrados = await ref
          .read(directorioRepositoryProvider)
          .buscar(q);
      if (mounted && pedido == _pedido) {
        setState(() {
          _resultados = encontrados;
          _error = null;
          _buscando = false;
        });
      }
    } on DioException catch (e) {
      if (mounted && pedido == _pedido) {
        setState(() {
          _error = mensajeDeError(e, porDefecto: 'No se pudo buscar.');
          _buscando = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final texto = _texto.text.trim();
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          0,
          20,
          16 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Busca tu negocio',
              style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 12),
            TextField(
              key: const Key('buscar-negocio-nombre'),
              controller: _texto,
              autofocus: true,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                labelText: 'Nombre o ciudad del negocio',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: _buscando
                    ? const Padding(
                        padding: EdgeInsets.all(14),
                        child: SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                      )
                    : null,
              ),
              onChanged: _alEscribir,
            ),
            const SizedBox(height: 8),
            if (_error != null)
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              )
            else if (!_buscando && _resultados.isEmpty)
              Text(
                texto.isEmpty
                    ? 'Aún no hay negocios en el directorio de ${AppConfig.nombreApp}.'
                    : 'No encontramos «$texto». Si tienes su dirección, escríbela '
                          'en «Dirección del negocio».',
              ),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final n in _resultados)
                    ListTile(
                      key: ValueKey('negocio-${n.slug}'),
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.storefront_outlined),
                      title: Text(n.nombre),
                      subtitle: n.lugar == null ? null : Text(n.lugar!),
                      onTap: () => Navigator.of(context).pop(n),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
