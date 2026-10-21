import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../../agenda/data/agenda_models.dart';
import '../../agenda/data/agenda_repository.dart';
import '../../auth/application/sesion_controller.dart';
import '../../cuenta/presentation/cuenta_widgets.dart';
import '../data/accesos_repository.dart';
import '../data/lectura_pase.dart';

/// La vista de la cámara que lee códigos: llama a [alLeer] con cada uno que lee.
/// En las pruebas se cambia por una sin cámara.
typedef CamaraDePase =
    Widget Function(BuildContext context, ValueChanged<String> alLeer);

/// Escanear el pase QR del cliente en la entrada (como la recepción de la web): la
/// cámara lee el código, se registra la entrada y se ve quién es y si pasa (o por
/// qué no se pudo). Mientras se ve el resultado la cámara se apaga; «Escanear otro
/// pase» la vuelve a abrir. Al salir de la pantalla queda apagada.
class EscanerPaseScreen extends ConsumerStatefulWidget {
  const EscanerPaseScreen({super.key, this.camara = camaraDelTelefono});

  final CamaraDePase camara;

  @override
  ConsumerState<EscanerPaseScreen> createState() => _EscanerPaseScreenState();
}

class _EscanerPaseScreenState extends ConsumerState<EscanerPaseScreen> {
  final _lector = LectorDePase();
  LecturaPase? _resultado;
  var _registrando = false;

  /// Con varias sedes, en cuál está la entrada (null: cualquiera).
  List<SucursalAgenda> _sedes = const [];
  String? _sede;

  @override
  void initState() {
    super.initState();
    _cargarSedes();
  }

  Future<void> _cargarSedes() async {
    final repo = ref.read(agendaRepositoryProvider);
    if (repo == null ||
        !(ref.read(sesionProvider)?.puede('sucursales.ver') ?? false)) {
      return;
    }
    try {
      final sedes = await repo.sucursales();
      if (mounted) {
        setState(() => _sedes = sedes);
      }
    } on DioException {
      // Sin la lista, la entrada vale en cualquier sede (como sin elegir).
    }
  }

  Future<void> _alLeer(String codigo) async {
    final repo = ref.read(accesosRepositoryProvider);
    if (repo == null || !_lector.tomar(codigo)) {
      return;
    }
    setState(() {
      _registrando = true;
      _resultado = null;
    });
    final resultado = await repo.registrarPase(codigo, sucursalId: _sede);
    _lector.listo();
    if (mounted) {
      setState(() {
        _registrando = false;
        _resultado = resultado;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final resultado = _resultado;
    final suave = TextStyle(color: context.colores.textoSuave);
    return Scaffold(
      appBar: AppBar(title: const Text('Escanear pase')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          if (_sedes.length > 1) ...[
            DropdownButtonFormField<String?>(
              key: const Key('sede-entrada'),
              initialValue: _sede,
              decoration: const InputDecoration(labelText: 'Sede'),
              items: [
                const DropdownMenuItem(
                  value: null,
                  child: Text('Todas las sedes'),
                ),
                for (final s in _sedes)
                  DropdownMenuItem(value: s.id, child: Text(s.nombre)),
              ],
              onChanged: _registrando ? null : (v) => setState(() => _sede = v),
            ),
            const SizedBox(height: 16),
          ],
          if (_registrando)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 48),
              child: Column(
                children: [
                  const CircularProgressIndicator(),
                  const SizedBox(height: 12),
                  Text('Registrando la entrada…', style: suave),
                ],
              ),
            )
          else if (resultado != null)
            _Resultado(
              resultado,
              onOtro: () => setState(() => _resultado = null),
            )
          else ...[
            // Solo mientras se espera un pase: con el resultado a la vista, la
            // cámara se apaga.
            ClipRRect(
              borderRadius: BorderRadius.circular(16),
              child: AspectRatio(
                aspectRatio: 1,
                child: widget.camara(context, _alLeer),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              'Apunta la cámara al código QR del pase.',
              textAlign: TextAlign.center,
              style: suave,
            ),
          ],
        ],
      ),
    );
  }
}

/// Quién es y si pasa (o por qué no se registró), y escanear el siguiente.
class _Resultado extends StatelessWidget {
  const _Resultado(this.lectura, {required this.onOtro});

  final LecturaPase lectura;
  final VoidCallback onOtro;

  @override
  Widget build(BuildContext context) {
    final suave = TextStyle(color: context.colores.textoSuave);
    final lectura = this.lectura;
    return Card(
      key: const Key('resultado-pase'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ...switch (lectura) {
              EntradaRegistrada() => [
                EstadoPunto(
                  lectura.titulo,
                  color: lectura.permitido
                      ? context.colores.exito
                      : context.colores.error,
                ),
                const SizedBox(height: 8),
                Text(
                  lectura.nombre ?? '—',
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                if (lectura.motivo.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(lectura.motivo, style: suave),
                ],
              ],
              PaseNoRegistrado(:final mensaje) => [
                EstadoPunto(
                  'No se registró la entrada',
                  color: context.colores.error,
                ),
                const SizedBox(height: 8),
                Text(mensaje),
              ],
            },
            const SizedBox(height: 16),
            FilledButton(
              onPressed: onOtro,
              child: const Text('Escanear otro pase'),
            ),
          ],
        ),
      ),
    );
  }
}

/// La cámara trasera del teléfono leyendo códigos (mobile_scanner). El propio
/// widget la apaga al salir de la pantalla y al mandar la app al fondo.
Widget camaraDelTelefono(BuildContext context, ValueChanged<String> alLeer) =>
    MobileScanner(
      onDetect: (captura) {
        for (final codigo in captura.barcodes) {
          final valor = codigo.rawValue;
          if (valor != null && valor.isNotEmpty) {
            alLeer(valor);
            return;
          }
        }
      },
      errorBuilder: (context, error) => ColoredBox(
        color: Colors.black,
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Text(
              error.errorCode == MobileScannerErrorCode.permissionDenied
                  ? 'La app no tiene permiso de usar la cámara. Dáselo en los '
                        'ajustes del teléfono.'
                  : 'No se pudo abrir la cámara.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white),
            ),
          ),
        ),
      ),
    );
