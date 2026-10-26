import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../data/agenda_models.dart';
import '../data/agenda_repository.dart';

/// Buscar a un cliente del negocio en el servidor (por nombre, correo o celular,
/// los primeros 8), como el buscador de la web: para agregarlo a una clase o
/// venderle. Con dos letras empieza a buscar; al tocar uno, [onElegir].
class BuscarPersona extends ConsumerStatefulWidget {
  const BuscarPersona({
    super.key,
    required this.onElegir,
    this.etiqueta = 'Buscar por nombre, correo o celular',
    this.habilitado = true,
  });

  final ValueChanged<ClienteAgenda> onElegir;
  final String etiqueta;
  final bool habilitado;

  @override
  ConsumerState<BuscarPersona> createState() => _BuscarPersonaState();
}

class _BuscarPersonaState extends ConsumerState<BuscarPersona> {
  final _texto = TextEditingController();
  Timer? _espera;

  /// Solo cuenta la respuesta de la última búsqueda.
  var _pedido = 0;
  var _buscando = false;
  List<ClienteAgenda>? _resultados;
  String? _error;

  @override
  void dispose() {
    _espera?.cancel();
    _texto.dispose();
    super.dispose();
  }

  void _alEscribir(String texto) {
    _espera?.cancel();
    final t = texto.trim();
    if (t.length < 2) {
      _pedido++;
      setState(() {
        _resultados = null;
        _error = null;
        _buscando = false;
      });
      return;
    }
    setState(() => _buscando = true);
    _espera = Timer(const Duration(milliseconds: 250), () => _buscar(t));
  }

  Future<void> _buscar(String texto) async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final pedido = ++_pedido;
    try {
      final encontrados = await repo.buscarClientes(texto);
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

  void _elegir(ClienteAgenda c) {
    _pedido++;
    _texto.clear();
    setState(() {
      _resultados = null;
      _buscando = false;
    });
    widget.onElegir(c);
  }

  @override
  Widget build(BuildContext context) {
    final resultados = _resultados;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        TextField(
          key: const Key('buscar-persona'),
          controller: _texto,
          enabled: widget.habilitado,
          autocorrect: false,
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(
            labelText: widget.etiqueta,
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
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              _error!,
              style: TextStyle(color: context.colores.error),
            ),
          )
        else if (resultados != null && resultados.isEmpty && !_buscando)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(
              'Nadie coincide con «${_texto.text.trim()}».',
              style: TextStyle(color: context.colores.textoSuave),
            ),
          )
        else if (resultados != null)
          for (final c in resultados)
            ListTile(
              key: ValueKey('persona-${c.id}'),
              contentPadding: EdgeInsets.zero,
              title: Text(c.nombre),
              subtitle: c.detalle == null ? null : Text(c.detalle!),
              onTap: widget.habilitado ? () => _elegir(c) : null,
            ),
      ],
    );
  }
}
