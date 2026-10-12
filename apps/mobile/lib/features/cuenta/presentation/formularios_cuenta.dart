import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/formato.dart';
import '../../../core/network/mensaje_error.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../data/cuenta_repository.dart';
import '../data/formularios.dart';
import 'cuenta_widgets.dart';

/// Las fichas de datos que le pide el negocio (de la cuenta o de la persona a su
/// cargo); se vuelven a leer al guardar.
final formulariosCuentaProvider =
    FutureProvider.autoDispose<FormulariosDeCuenta>((ref) async {
      final repo = ref.watch(cuentaRepositoryProvider);
      return repo == null ? FormulariosDeCuenta.vacio : repo.formularios();
    });

/// «Ficha de datos» del Expediente (como en la web): cada formulario, si ya lo
/// respondió y cuándo; tocarlo abre sus respuestas para verlas o corregirlas.
/// Sin formularios, no se muestra.
class FormulariosSeccion extends ConsumerWidget {
  const FormulariosSeccion({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final datos = ref.watch(formulariosCuentaProvider).value;
    if (datos == null || datos.personaId == null || datos.formularios.isEmpty) {
      return const SizedBox.shrink();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const TituloSeccion('Ficha de datos'),
        Card(
          key: const Key('formularios'),
          child: Column(
            children: [
              for (final f in datos.formularios)
                ListTile(
                  title: Text(f.nombre),
                  subtitle: Text(
                    f.respondidoEn == null
                        ? 'Sin responder'
                        : 'Respondido el ${Formato.dia(f.respondidoEn!)}',
                    style: TextStyle(
                      color: f.respondidoEn == null
                          ? context.colores.aviso
                          : context.colores.textoSuave,
                    ),
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => FormularioScreen(
                        formulario: f,
                        personaId: datos.personaId!,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Llenar o corregir un formulario. Quien no puede responder (sin el permiso) solo
/// ve sus respuestas.
class FormularioScreen extends ConsumerStatefulWidget {
  const FormularioScreen({
    super.key,
    required this.formulario,
    required this.personaId,
  });

  final FormularioPersona formulario;
  final String personaId;

  @override
  ConsumerState<FormularioScreen> createState() => _FormularioScreenState();
}

class _FormularioScreenState extends ConsumerState<FormularioScreen> {
  final _forma = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _textos = {
    for (final c in widget.formulario.campos)
      if (const ['texto', 'textarea', 'numero'].contains(c.tipo))
        c.id: TextEditingController(text: c.valor == null ? '' : '${c.valor}'),
  };
  late final Map<String, Object?> _otros = {
    for (final c in widget.formulario.campos)
      if (!_textos.containsKey(c.id))
        c.id: c.tipo == 'booleano' ? c.valor == true : c.valor,
  };
  var _guardando = false;

  @override
  void dispose() {
    for (final t in _textos.values) {
      t.dispose();
    }
    super.dispose();
  }

  Future<void> _guardar() async {
    if (!(_forma.currentState?.validate() ?? false)) {
      return;
    }
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    // Lo vacío no se manda (como en la web); sí/no siempre.
    final valores = <String, Object>{};
    for (final c in widget.formulario.campos) {
      final texto = _textos[c.id]?.text.trim();
      if (texto != null) {
        if (texto.isNotEmpty) {
          valores[c.id] = c.tipo == 'numero' ? num.parse(texto) : texto;
        }
        continue;
      }
      final v = _otros[c.id];
      if (v != null && v != '') {
        valores[c.id] = v;
      }
    }
    setState(() => _guardando = true);
    final mensajero = ScaffoldMessenger.of(context);
    final navegador = Navigator.of(context);
    try {
      await repo.responderFormulario(
        widget.formulario.id,
        widget.personaId,
        valores,
      );
      ref.invalidate(formulariosCuentaProvider);
      mensajero.showSnackBar(
        const SnackBar(content: Text('Respuestas guardadas.')),
      );
      navegador.pop();
    } on DioException catch (e) {
      mensajero.showSnackBar(
        SnackBar(
          content: Text(
            mensajeDeError(e, porDefecto: 'No se pudieron guardar.'),
          ),
        ),
      );
    } finally {
      if (mounted) {
        setState(() => _guardando = false);
      }
    }
  }

  String? _requerido(CampoFormulario c, Object? v) =>
      c.obligatorio && (v == null || '$v'.trim().isEmpty)
      ? 'Este dato es obligatorio.'
      : null;

  Widget _campo(CampoFormulario c, bool editable) {
    final etiqueta = c.obligatorio ? '${c.etiqueta} *' : c.etiqueta;
    switch (c.tipo) {
      case 'booleano':
        return SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(c.etiqueta),
          value: _otros[c.id] == true,
          onChanged: editable ? (v) => setState(() => _otros[c.id] = v) : null,
        );
      case 'seleccion':
        final actual = _otros[c.id] is String ? _otros[c.id] as String : null;
        return DropdownButtonFormField<String>(
          initialValue: c.opciones.contains(actual) ? actual : null,
          decoration: InputDecoration(labelText: etiqueta),
          items: [
            for (final o in c.opciones)
              DropdownMenuItem(value: o, child: Text(o)),
          ],
          onChanged: editable ? (v) => setState(() => _otros[c.id] = v) : null,
          validator: (v) => _requerido(c, v),
        );
      case 'fecha':
        final valor = _otros[c.id] is String ? _otros[c.id] as String : null;
        final dia = valor == null ? null : DateTime.tryParse(valor);
        return FormField<String>(
          initialValue: valor,
          validator: (_) => _requerido(c, _otros[c.id]),
          builder: (estado) => InkWell(
            onTap: !editable
                ? null
                : () async {
                    final elegido = await showDatePicker(
                      context: context,
                      initialDate: dia ?? DateTime.now(),
                      firstDate: DateTime(1900),
                      lastDate: DateTime(2100),
                    );
                    if (elegido != null) {
                      setState(() => _otros[c.id] = Formato.iso(elegido));
                      estado.didChange(Formato.iso(elegido));
                    }
                  },
            child: InputDecorator(
              decoration: InputDecoration(
                labelText: etiqueta,
                errorText: estado.errorText,
              ),
              child: Text(
                dia == null
                    ? '—'
                    : '${Formato.fechaLarga(valor)} de ${dia.year}',
              ),
            ),
          ),
        );
      default:
        final numero = c.tipo == 'numero';
        return TextFormField(
          controller: _textos[c.id],
          enabled: editable,
          minLines: c.tipo == 'textarea' ? 3 : 1,
          maxLines: c.tipo == 'textarea' ? 6 : 1,
          keyboardType: numero
              ? const TextInputType.numberWithOptions(decimal: true)
              : null,
          decoration: InputDecoration(labelText: etiqueta),
          validator: (v) {
            final falta = _requerido(c, v);
            if (falta != null) {
              return falta;
            }
            if (numero &&
                (v ?? '').trim().isNotEmpty &&
                num.tryParse(v!.trim()) == null) {
              return 'Escribe un número.';
            }
            return null;
          },
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final f = widget.formulario;
    final editable =
        ref.watch(sesionProvider)?.puede('formularios.responder') ?? false;
    return Scaffold(
      appBar: AppBar(title: Text(f.nombre)),
      body: Form(
        key: _forma,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          children: [
            if (f.descripcion != null && f.descripcion!.isNotEmpty) ...[
              Text(
                f.descripcion!,
                style: TextStyle(color: context.colores.textoSuave),
              ),
              const SizedBox(height: 12),
            ],
            for (final c in f.campos) ...[
              _campo(c, editable && !_guardando),
              const SizedBox(height: 12),
            ],
            if (editable)
              FilledButton(
                onPressed: _guardando ? null : _guardar,
                child: Text(_guardando ? 'Guardando…' : 'Guardar'),
              ),
          ],
        ),
      ),
    );
  }
}
