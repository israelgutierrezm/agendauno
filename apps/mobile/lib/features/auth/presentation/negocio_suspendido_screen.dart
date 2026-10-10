import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/tema_agendauno.dart';
import '../application/sesion_controller.dart';

/// El negocio está suspendido por falta de pago de su suscripción (ADR 0073): el
/// servidor solo deja abiertos /yo y el acceso, así que en lugar de pestañas que no
/// cargarían se dice qué pasa. A quien puede ver la facturación (el propietario) se
/// le dice cómo reactivarlo; a los demás, solo que no está disponible. Al volver a
/// la app (o con «Volver a revisar») se revisa de nuevo: pagada la renta, entra.
class NegocioSuspendidoScreen extends ConsumerStatefulWidget {
  const NegocioSuspendidoScreen({super.key});

  @override
  ConsumerState<NegocioSuspendidoScreen> createState() =>
      _NegocioSuspendidoScreenState();
}

class _NegocioSuspendidoScreenState
    extends ConsumerState<NegocioSuspendidoScreen> {
  bool _revisando = false;

  Future<void> _revisar() async {
    setState(() => _revisando = true);
    await ref.read(sesionProvider.notifier).refrescar();
    if (mounted) {
      setState(() => _revisando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    // Quien paga la renta (el servidor solo lo deja entrar a él con el negocio
    // suspendido) o alguien del equipo o un cliente con una sesión anterior.
    final puedePagar = sesion?.puede('facturacion.ver') ?? false;
    final negocio = sesion?.estudioNombre;

    return Scaffold(
      key: const Key('negocio-suspendido'),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 32, 20, 20),
          children: [
            Text(
              negocio == null || negocio.isEmpty ? 'AgendaUno' : negocio,
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(
              puedePagar
                  ? 'Tu negocio está suspendido por falta de pago. Paga tu '
                        'suscripción en agendauno.mx para reactivarlo.'
                  : 'Este negocio no está disponible por ahora.',
              style: const TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _revisando ? null : _revisar,
              child: const Text('Volver a revisar'),
            ),
            const SizedBox(height: 4),
            TextButton(
              onPressed: _revisando
                  ? null
                  : () => ref.read(sesionProvider.notifier).cerrar(),
              child: const Text('Cerrar sesión'),
            ),
          ],
        ),
      ),
    );
  }
}
