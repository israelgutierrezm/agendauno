import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../cuenta/presentation/cuenta_widgets.dart';
import '../data/catalogos_sat.dart';
import '../data/datos_fiscales.dart';

/// «Datos para facturar» en Mi perfil del cliente (como la web): su RFC, nombre,
/// código postal, régimen y uso del CFDI, para que el negocio le facture sin
/// pedírselos cada vez. Los errores del servidor salen junto a cada campo. Si el
/// negocio factura sus citas en automático, también puede pedirlo; y puede borrar
/// sus datos (con confirmación).
class DatosFiscalesCard extends ConsumerStatefulWidget {
  const DatosFiscalesCard({super.key});

  @override
  ConsumerState<DatosFiscalesCard> createState() => _DatosFiscalesCardState();
}

class _DatosFiscalesCardState extends ConsumerState<DatosFiscalesCard> {
  final _rfc = TextEditingController();
  final _nombre = TextEditingController();
  final _codigoPostal = TextEditingController();
  final _email = TextEditingController();
  String? _regimen;
  String _uso = 'G03';
  bool _automatica = false;

  bool _cargando = true;
  bool _errorCarga = false;
  bool _guardando = false;
  DatosFiscales? _guardados;
  bool _automaticaDisponible = false;
  Map<String, String> _errores = const {};

  @override
  void initState() {
    super.initState();
    _cargar();
  }

  @override
  void dispose() {
    for (final c in [_rfc, _nombre, _codigoPostal, _email]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _cargar() async {
    final repo = ref.read(datosFiscalesRepositoryProvider);
    if (repo == null) {
      return;
    }
    setState(() {
      _cargando = true;
      _errorCarga = false;
    });
    try {
      final mios = await repo.cargar();
      if (mounted) {
        setState(() {
          _tomar(mios);
          _cargando = false;
        });
      }
    } on DioException {
      if (mounted) {
        setState(() {
          _cargando = false;
          _errorCarga = true;
        });
      }
    }
  }

  /// Lo que respondió el servidor pasa al formulario.
  void _tomar(MisDatosFiscales mios) {
    _automaticaDisponible = mios.automaticaDisponible;
    _guardados = mios.datos;
    final d = mios.datos;
    _rfc.text = d?.rfc ?? '';
    _nombre.text = d?.nombre ?? '';
    _codigoPostal.text = d?.codigoPostal ?? '';
    _email.text = d?.email ?? '';
    _regimen = d == null || d.regimenFiscal.isEmpty ? null : d.regimenFiscal;
    _uso = d == null || d.usoCfdi.isEmpty ? 'G03' : d.usoCfdi;
    _automatica = d?.facturaAutomatica ?? false;
  }

  Future<void> _guardar() async {
    final repo = ref.read(datosFiscalesRepositoryProvider);
    if (repo == null) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    setState(() {
      _guardando = true;
      _errores = const {};
    });
    try {
      final mios = await repo.guardar(
        DatosFiscales(
          rfc: _rfc.text,
          nombre: _nombre.text,
          codigoPostal: _codigoPostal.text,
          regimenFiscal: _regimen ?? '',
          usoCfdi: _uso,
          email: _email.text,
          // Sin la factura automática en el negocio, no se pide.
          facturaAutomatica: _automaticaDisponible && _automatica,
        ),
      );
      if (!mounted) {
        return;
      }
      setState(() => _tomar(mios));
      messenger.showSnackBar(
        const SnackBar(content: Text('Datos para facturar guardados.')),
      );
    } on DatosFiscalesInvalidos catch (e) {
      if (mounted) {
        setState(() => _errores = e.errores);
      }
    } on DioException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
      }
    }
  }

  Future<void> _borrar() async {
    final repo = ref.read(datosFiscalesRepositoryProvider);
    final messenger = ScaffoldMessenger.of(context);
    final confirmado = await showDialog<bool>(
      context: context,
      builder: (dialogo) => AlertDialog(
        title: const Text('Borrar mis datos'),
        content: const Text(
          '¿Borrar tus datos para facturar? Ya no se te facturará en automático.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogo).pop(false),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            key: const Key('confirmar-borrar-fiscales'),
            style: FilledButton.styleFrom(
              backgroundColor: dialogo.colores.error,
            ),
            onPressed: () => Navigator.of(dialogo).pop(true),
            child: const Text('Borrar'),
          ),
        ],
      ),
    );
    if (confirmado != true || repo == null || !mounted) {
      return;
    }
    setState(() => _guardando = true);
    try {
      await repo.borrar();
      if (!mounted) {
        return;
      }
      setState(() {
        _tomar(MisDatosFiscales(automaticaDisponible: _automaticaDisponible));
        _errores = const {};
      });
      messenger.showSnackBar(
        const SnackBar(content: Text('Borramos tus datos para facturar.')),
      );
    } on DioException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mensajeDeError(e))));
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
      }
    }
  }

  /// Un selector del catálogo: las claves que le corresponden al tipo de persona
  /// del RFC (la elegida se conserva aunque no le corresponda: el servidor dice
  /// por qué).
  Widget _selector({
    required Key key,
    required String etiqueta,
    required List<ClaveSat> catalogo,
    required String? valor,
    required String campo,
    required ValueChanged<String?> onChanged,
  }) {
    final opciones = clavesPara(
      catalogo,
      tipoPersonaDeRfc(_rfc.text),
      actual: valor,
    );
    return DropdownButtonFormField<String>(
      key: key,
      initialValue: valor,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: etiqueta,
        errorText: _errores[campo],
        errorMaxLines: 3,
      ),
      hint: const Text('Elige uno'),
      items: [
        for (final c in opciones)
          DropdownMenuItem(
            value: c.clave,
            child: Text(c.etiqueta, overflow: TextOverflow.ellipsis),
          ),
      ],
      onChanged: _guardando ? null : onChanged,
    );
  }

  @override
  Widget build(BuildContext context) {
    final suave = TextStyle(color: context.colores.textoSuave);
    return Card(
      key: const Key('datos-facturar'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Datos para facturar',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 4),
            if (_cargando)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 16),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_errorCarga) ...[
              Text(
                'No se pudieron cargar tus datos para facturar.',
                style: suave,
              ),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton(
                  onPressed: _cargar,
                  child: const Text('Reintentar'),
                ),
              ),
            ] else ...[
              EstadoPunto(
                _guardados == null ? 'Aún no los guardas' : 'Guardados',
                color: _guardados == null
                    ? context.colores.textoSuave
                    : context.colores.exito,
              ),
              const SizedBox(height: 6),
              Text(
                'Guárdalos una vez y el negocio los usa cada vez que te factura. '
                'Deben coincidir con tu constancia de situación fiscal.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('fiscal-rfc'),
                controller: _rfc,
                enabled: !_guardando,
                maxLength: 13,
                textCapitalization: TextCapitalization.characters,
                autocorrect: false,
                // Con el RFC se sabe si es persona física o moral (los catálogos).
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                  labelText: 'RFC',
                  errorText: _errores['rfc'],
                  errorMaxLines: 3,
                  counterText: '',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('fiscal-nombre'),
                controller: _nombre,
                enabled: !_guardando,
                maxLength: 255,
                textCapitalization: TextCapitalization.characters,
                decoration: InputDecoration(
                  labelText: 'Nombre o razón social',
                  helperText:
                      'Tal como aparece en la constancia, sin el régimen de capital.',
                  helperMaxLines: 2,
                  errorText: _errores['nombre'],
                  errorMaxLines: 3,
                  counterText: '',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('fiscal-codigo-postal'),
                controller: _codigoPostal,
                enabled: !_guardando,
                maxLength: 5,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(
                  labelText: 'Código postal fiscal',
                  errorText: _errores['codigo_postal'],
                  errorMaxLines: 3,
                  counterText: '',
                ),
              ),
              const SizedBox(height: 12),
              _selector(
                key: const Key('fiscal-regimen'),
                etiqueta: 'Régimen fiscal',
                catalogo: regimenesFiscales,
                valor: _regimen,
                campo: 'regimen_fiscal',
                onChanged: (v) => setState(() => _regimen = v),
              ),
              const SizedBox(height: 12),
              _selector(
                key: const Key('fiscal-uso'),
                etiqueta: 'Uso del CFDI',
                catalogo: usosCfdi,
                valor: _uso,
                campo: 'uso_cfdi',
                onChanged: (v) => setState(() => _uso = v ?? _uso),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('fiscal-email'),
                controller: _email,
                enabled: !_guardando,
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                decoration: InputDecoration(
                  labelText: 'Correo para recibir la factura (opcional)',
                  errorText: _errores['email'],
                  errorMaxLines: 3,
                ),
              ),
              if (_automaticaDisponible) ...[
                const SizedBox(height: 8),
                SwitchListTile(
                  key: const Key('factura-automatica'),
                  contentPadding: EdgeInsets.zero,
                  title: const Text(
                    'Facturar mis pagos de citas en automático',
                  ),
                  value: _automatica,
                  onChanged: _guardando
                      ? null
                      : (v) => setState(() => _automatica = v),
                ),
              ],
              const SizedBox(height: 12),
              FilledButton(
                key: const Key('guardar-fiscales'),
                onPressed: _guardando ? null : _guardar,
                child: Text(_guardando ? 'Guardando…' : 'Guardar datos'),
              ),
              if (_guardados != null) ...[
                const SizedBox(height: 4),
                TextButton(
                  key: const Key('borrar-fiscales'),
                  style: TextButton.styleFrom(
                    foregroundColor: context.colores.error,
                  ),
                  onPressed: _guardando ? null : _borrar,
                  child: const Text('Borrar mis datos'),
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}
