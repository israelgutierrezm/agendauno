import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../perfil/presentation/perfil_screen.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/cuenta_repository.dart';
import 'agendar_cita_sheet.dart';
import 'mis_documentos_screen.dart';
import 'pase_sheet.dart';

/// Autoservicio del alumno o cliente: consentimientos por firmar, créditos,
/// reservas y, según el negocio, las próximas clases o agendar una cita.
class CuentaScreen extends ConsumerStatefulWidget {
  const CuentaScreen({super.key});

  @override
  ConsumerState<CuentaScreen> createState() => _CuentaScreenState();
}

class _CuentaScreenState extends ConsumerState<CuentaScreen> {
  // Al volver del navegador (pago en línea) se actualiza la cuenta.
  late final AppLifecycleListener _ciclo = AppLifecycleListener(
    onResume: () => ref.invalidate(cuentaProvider),
  );

  @override
  void initState() {
    super.initState();
    _ciclo;
  }

  @override
  void dispose() {
    _ciclo.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final sesion = ref.watch(sesionProvider);
    final estado = ref.watch(cuentaProvider);
    final esCitas = sesion?.esCitas ?? false;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Mi cuenta'),
        actions: [
          IconButton(
            icon: const Icon(Icons.person_outline),
            tooltip: 'Mi perfil',
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute<void>(builder: (_) => const PerfilScreen()),
            ),
          ),
        ],
      ),
      body: estado.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) =>
            _Error(onReintentar: () => ref.invalidate(cuentaProvider)),
        data: (cuenta) => RefreshIndicator(
          onRefresh: () => ref.refresh(cuentaProvider.future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            children: [
              for (final c in cuenta.consentimientos) _Consentimiento(c),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.qr_code_2),
                  title: const Text('Mi pase de entrada'),
                  subtitle: const Text('Muéstralo en recepción'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => showModalBottomSheet<void>(
                    context: context,
                    showDragHandle: true,
                    builder: (_) => const PaseSheet(),
                  ),
                ),
              ),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.description_outlined),
                  title: const Text('Mis documentos'),
                  subtitle: const Text('Los que te pide el negocio'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => const MisDocumentosScreen(),
                    ),
                  ),
                ),
              ),
              const _Titulo('Mis créditos'),
              _Creditos(cuenta.derechos),
              const _Titulo('Mis reservas'),
              if (cuenta.reservas.isEmpty)
                const _Vacio('No tienes reservas próximas.')
              else
                ...cuenta.reservas.map(
                  (r) => _Reserva(r, pagoEnLinea: cuenta.pagoEnLinea),
                ),
              if (esCitas) ...[
                const _Titulo('Agendar una cita'),
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.event_available_outlined),
                    title: const Text('Elige servicio, profesional y hora'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => showModalBottomSheet<void>(
                      context: context,
                      isScrollControlled: true,
                      showDragHandle: true,
                      builder: (_) => const AgendarCitaSheet(),
                    ),
                  ),
                ),
              ] else ...[
                const _Titulo('Próximas clases'),
                if (cuenta.clases.isEmpty)
                  const _Vacio('No hay clases programadas.')
                else
                  ...cuenta.clases.map(
                    (c) => _Clase(
                      c,
                      reservada: cuenta.sesionesReservadas.contains(c.id),
                    ),
                  ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Ejecuta una acción y muestra el mensaje del servidor si falla.
Future<void> hacerConAviso(
  BuildContext context,
  Future<void> Function() accion, {
  String? exito,
}) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    await accion();
    if (exito != null) {
      messenger.showSnackBar(SnackBar(content: Text(exito)));
    }
  } on DioException catch (e) {
    final data = e.response?.data;
    final msg = (data is Map && data['message'] is String)
        ? data['message'] as String
        : 'No se pudo completar la acción.';
    messenger.showSnackBar(SnackBar(content: Text(msg)));
  }
}

class _Consentimiento extends ConsumerWidget {
  const _Consentimiento(this.c);

  final ConsentimientoPendiente c;

  @override
  Widget build(BuildContext context, WidgetRef ref) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(c.titulo, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Text(
            c.contenido,
            style: const TextStyle(color: TemaAgendaUno.textoSuave),
          ),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: () => hacerConAviso(
              context,
              () => ref.read(cuentaProvider.notifier).firmar(c.id),
              exito: 'Gracias, quedó firmado.',
            ),
            child: const Text('Acepto'),
          ),
        ],
      ),
    ),
  );
}

class _Creditos extends StatelessWidget {
  const _Creditos(this.derechos);

  final List<DerechoMiembro> derechos;

  @override
  Widget build(BuildContext context) {
    if (derechos.isEmpty) {
      return const _Vacio('Aún no tienes créditos.');
    }
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: derechos
              .map(
                (d) => Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (d.pausaHasta != null)
                      Text(
                        '${d.producto != null ? '${d.producto} · ' : ''}En pausa hasta el ${Formato.dia(DateTime.parse(d.pausaHasta!))}',
                        style: const TextStyle(color: TemaAgendaUno.textoSuave),
                      ),
                    Text(
                      d.ilimitado
                          ? 'Ilimitado'
                          : '${d.creditosDisponibles} créditos disponibles',
                      style: const TextStyle(
                        fontSize: 20,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              )
              .toList(),
        ),
      ),
    );
  }
}

class _Reserva extends ConsumerWidget {
  const _Reserva(this.r, {this.pagoEnLinea = false});

  final ReservaMiembro r;
  final bool pagoEnLinea;

  bool get _sePagaAqui =>
      r.estado == 'pendiente_pago' && pagoEnLinea && r.ordenId != null;

  /// Abre la página de pago de la pasarela en el navegador; al volver a la app la
  /// cuenta se actualiza (el webhook confirma el pago).
  Future<void> _pagar(BuildContext context, WidgetRef ref) async {
    final repo = ref.read(cuentaRepositoryProvider);
    if (repo == null) {
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    await hacerConAviso(context, () async {
      final url = await repo.pagarOrden(r.ordenId!);
      if (url == null) {
        await ref.read(cuentaProvider.notifier).recargar();
        return;
      }
      final abierto = await launchUrl(
        Uri.parse(url),
        mode: LaunchMode.externalApplication,
      );
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            abierto
                ? 'Completa el pago en el navegador; al volver actualizamos tu cuenta.'
                : 'No se pudo abrir la página de pago.',
          ),
        ),
      );
    });
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifier = ref.read(cuentaProvider.notifier);
    final aviso =
        r.ofrecida || r.estado == 'pendiente_pago' || r.estado == 'en_espera';
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              r.oferta ?? '—',
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 2),
            Text(
              [
                Formato.fechaHora(r.iniciaEn),
                if (r.sucursal != null) r.sucursal!,
              ].join(' · '),
              style: const TextStyle(color: TemaAgendaUno.textoSuave),
            ),
            const SizedBox(height: 4),
            Text(
              r.estadoTexto,
              style: TextStyle(
                color: aviso ? TemaAgendaUno.aviso : TemaAgendaUno.exito,
                fontWeight: FontWeight.w600,
              ),
            ),
            if (r.estado == 'pendiente_pago' && !_sePagaAqui)
              const Padding(
                padding: EdgeInsets.only(top: 4),
                child: Text(
                  'Págala en el negocio o desde la web para confirmarla.',
                  style: TextStyle(
                    color: TemaAgendaUno.textoSuave,
                    fontSize: 13,
                  ),
                ),
              ),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                if (_sePagaAqui)
                  FilledButton(
                    onPressed: () => _pagar(context, ref),
                    child: const Text('Pagar'),
                  ),
                if (r.ofrecida)
                  FilledButton(
                    onPressed: () => hacerConAviso(
                      context,
                      () => notifier.aceptarLugar(r.id),
                      exito: '¡Lugar confirmado!',
                    ),
                    child: const Text('Aceptar lugar'),
                  ),
                TextButton(
                  style: TextButton.styleFrom(
                    foregroundColor: TemaAgendaUno.error,
                  ),
                  onPressed: () => hacerConAviso(
                    context,
                    () => notifier.cancelar(r.id),
                    exito: 'Reserva cancelada.',
                  ),
                  child: const Text('Cancelar'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Clase extends ConsumerWidget {
  const _Clase(this.c, {required this.reservada});

  final ClaseMiembro c;
  final bool reservada;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifier = ref.read(cuentaProvider.notifier);
    final lugares = c.capacidad != null
        ? '${c.ocupados}/${c.capacidad}'
        : '${c.ocupados}';
    final Widget accion;
    if (reservada) {
      accion = const Text(
        'Reservada',
        style: TextStyle(
          color: TemaAgendaUno.exito,
          fontWeight: FontWeight.w600,
        ),
      );
    } else if (c.llena) {
      accion = OutlinedButton(
        onPressed: () => hacerConAviso(
          context,
          () => notifier.reservar(c.id, esperar: true),
          exito: 'Te anotamos en la lista de espera.',
        ),
        child: const Text('Lista de espera'),
      );
    } else {
      accion = FilledButton(
        onPressed: () => hacerConAviso(
          context,
          () => notifier.reservar(c.id),
          exito: 'Reservado.',
        ),
        child: const Text('Reservar'),
      );
    }

    return Card(
      child: ListTile(
        title: Text(c.oferta ?? '—'),
        subtitle: Text(
          [
            Formato.fechaHora(c.iniciaEn),
            if (c.sucursal != null) c.sucursal!,
            lugares,
          ].join(' · '),
        ),
        trailing: accion,
      ),
    );
  }
}

class _Titulo extends StatelessWidget {
  const _Titulo(this.texto);

  final String texto;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 20, bottom: 8),
    child: Text(texto, style: Theme.of(context).textTheme.titleMedium),
  );
}

class _Vacio extends StatelessWidget {
  const _Vacio(this.texto);

  final String texto;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: Text(texto, style: const TextStyle(color: TemaAgendaUno.textoSuave)),
  );
}

class _Error extends StatelessWidget {
  const _Error({required this.onReintentar});

  final VoidCallback onReintentar;

  @override
  Widget build(BuildContext context) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Text('No se pudo cargar tu cuenta.'),
        const SizedBox(height: 8),
        OutlinedButton(
          onPressed: onReintentar,
          child: const Text('Reintentar'),
        ),
      ],
    ),
  );
}
