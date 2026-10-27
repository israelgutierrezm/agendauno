import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';

/// «¿Tienes un cupón?» (ADR 0220), como en la web: se abre, se escribe el código,
/// se revisa cuánto descuenta y queda aplicado; el servidor lo vuelve a validar al
/// agendar. Quien lo usa da la función que lo revisa ([revisar], el descuento en
/// centavos o un [DioException] con el motivo) y el formato del dinero; si cambia
/// sobre qué se calcula (otro servicio), le da otra `key` para empezar de nuevo.
class CampoCupon extends StatefulWidget {
  const CampoCupon({
    super.key,
    required this.revisar,
    required this.dinero,
    required this.alCambiar,
    this.deshabilitado = false,
  });

  final Future<int> Function(String codigo) revisar;
  final String Function(int minor) dinero;

  /// El código aplicado (null: ninguno, o se quitó, o no sirvió).
  final ValueChanged<String?> alCambiar;
  final bool deshabilitado;

  @override
  State<CampoCupon> createState() => _CampoCuponState();
}

class _CampoCuponState extends State<CampoCupon> {
  final _codigo = TextEditingController();
  bool _abierto = false;
  bool _revisando = false;
  String? _aplicado;
  int _descuentoMinor = 0;
  String? _error;

  @override
  void dispose() {
    _codigo.dispose();
    super.dispose();
  }

  Future<void> _aplicar() async {
    final codigo = _codigo.text.trim();
    if (codigo.isEmpty || _revisando) {
      return;
    }
    setState(() {
      _revisando = true;
      _error = null;
    });
    try {
      final descuento = await widget.revisar(codigo);
      // Ya no está (cambió el servicio mientras se revisaba): no aplica nada.
      if (!mounted) {
        return;
      }
      setState(() {
        _aplicado = codigo;
        _descuentoMinor = descuento;
        _revisando = false;
      });
      widget.alCambiar(codigo);
    } on DioException catch (e) {
      if (!mounted) {
        return;
      }
      setState(() {
        _aplicado = null;
        _error = mensajeDeError(e, porDefecto: 'No se pudo revisar el cupón.');
        _revisando = false;
      });
      widget.alCambiar(null);
    }
  }

  void _quitar() {
    setState(() {
      _codigo.clear();
      _aplicado = null;
      _error = null;
    });
    widget.alCambiar(null);
  }

  @override
  Widget build(BuildContext context) {
    if (!_abierto) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton(
          key: const Key('abrir-cupon'),
          style: TextButton.styleFrom(padding: EdgeInsets.zero),
          onPressed: widget.deshabilitado
              ? null
              : () => setState(() => _abierto = true),
          child: const Text('¿Tienes un cupón?'),
        ),
      );
    }
    final aplicado = _aplicado;
    return Column(
      key: const Key('campo-cupon'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (aplicado != null)
          Row(
            children: [
              Expanded(
                child: Text(
                  'Cupón ${aplicado.toUpperCase()}: '
                  '−${widget.dinero(_descuentoMinor)}',
                  key: const Key('cupon-aplicado'),
                ),
              ),
              TextButton(
                onPressed: widget.deshabilitado ? null : _quitar,
                child: const Text('Quitar'),
              ),
            ],
          )
        else
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: TextField(
                  key: const Key('codigo-cupon'),
                  controller: _codigo,
                  enabled: !widget.deshabilitado,
                  autofocus: true,
                  maxLength: 64,
                  autocorrect: false,
                  enableSuggestions: false,
                  textCapitalization: TextCapitalization.characters,
                  textInputAction: TextInputAction.done,
                  decoration: const InputDecoration(
                    labelText: 'Código del cupón',
                    counterText: '',
                  ),
                  // Para habilitar «Aplicar» en cuanto hay algo escrito.
                  onChanged: (_) => setState(() {}),
                  onSubmitted: (_) => _aplicar(),
                ),
              ),
              const SizedBox(width: 8),
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: OutlinedButton(
                  key: const Key('aplicar-cupon'),
                  onPressed:
                      widget.deshabilitado ||
                          _revisando ||
                          _codigo.text.trim().isEmpty
                      ? null
                      : _aplicar,
                  child: const Text('Aplicar'),
                ),
              ),
            ],
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              _error!,
              key: const Key('error-cupon'),
              style: TextStyle(color: context.colores.error, fontSize: 13),
            ),
          ),
      ],
    );
  }
}
