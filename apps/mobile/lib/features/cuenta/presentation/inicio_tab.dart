import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/calendario/agregar_calendario.dart';
import '../../../core/formato.dart';
import '../../../core/theme/tema_agendauno.dart';
import '../../auth/application/sesion_controller.dart';
import '../../auth/data/sesion.dart';
import '../application/cuenta_controller.dart';
import '../data/cuenta_models.dart';
import '../data/eventos_cuenta.dart';
import 'cuenta_widgets.dart';
import 'pase_sheet.dart';
import 'tarjeta_principal.dart';

/// Pestañas del portal del alumno (el orden de la barra de abajo).
enum PestanaCuenta { inicio, reservas, pagos, expediente, cuenta }

/// Inicio del portal del alumno o cliente (como en la web): un saludo, la tarjeta
/// grande con su próxima clase o cita (siempre; sin reserva invita a reservar) con la
/// foto del giro y el clima, lo que pide su atención y accesos directos con su color.
class InicioTab extends ConsumerWidget {
  const InicioTab({super.key, required this.cuenta, required this.onIr});

  final MiCuenta cuenta;
  final void Function(PestanaCuenta) onIr;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    final terminos = sesion?.terminologia ?? const Terminologia();
    final clase = terminos.sesion.toLowerCase();
    final clases = terminos.sesiones.toLowerCase();
    final proximas = proximasReservas(cuenta);
    final proxima = proximas
        .where((r) => r.estado == 'confirmada' || r.estado == 'pendiente_pago')
        .firstOrNull;
    final ofrecida = cuenta.reservas.where((r) => r.ofrecida).firstOrNull;
    final disponibles = clasesDisponibles(cuenta).length;
    final firmar = cuenta.consentimientos.length;
    final pagar = cuenta.porPagar.length;
    final creditos = _creditos(cuenta.derechos);
    final clima = ref.watch(climaProvider).value;
    final esCitas = sesion?.esCitas ?? false;
    final nombre = _primerNombre(sesion);
    final estudio = sesion?.estudioNombre;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        Text(
          nombre.isEmpty ? '¡Hola!' : '¡Hola, $nombre!',
          style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 2),
        Text(
          estudio == null || estudio.isEmpty
              ? 'Aquí tienes un resumen de tu actividad.'
              : 'Aquí tienes un resumen de tu actividad en $estudio.',
          style: const TextStyle(color: TemaAgendaUno.textoSuave),
        ),
        const SizedBox(height: 16),

        // La tarjeta principal: siempre, con o sin reserva.
        TarjetaPrincipal(
          // Por lo que es: "Tu próxima cita" o "Tu próxima clase"; sin reserva,
          // el término general.
          etiqueta: proxima == null
              ? 'Tu próxima reserva'
              : (proxima.esCita ? 'Tu próxima cita' : 'Tu próxima $clase'),
          foto: fotoNegocio(sesion?.perfil),
          clima: clima,
          dondeClima:
              clima?.dondeTexto(proxima?.esCita == true ? 'cita' : clase) ?? '',
          contenido: proxima != null
              ? [
                  const SizedBox(height: 6),
                  Text(
                    proxima.oferta ?? '—',
                    style: const TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    [
                      Formato.fechaHora(proxima.iniciaEn),
                      if (proxima.sucursal != null) proxima.sucursal!,
                    ].join(' · '),
                  ),
                  if (proxima.estado == 'pendiente_pago')
                    const Text(
                      'Pendiente de pago',
                      style: TextStyle(color: TemaAgendaUno.aviso),
                    ),
                  const SizedBox(height: 14),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      BotonAgregarCalendario(
                        primario: true,
                        evento: 'reserva-${proxima.id}',
                        titulo: proxima.oferta ?? 'Reserva',
                        inicio: DateTime.parse(proxima.iniciaEn!).toLocal(),
                        fin: proxima.terminaEn == null
                            ? null
                            : DateTime.tryParse(proxima.terminaEn!)?.toLocal(),
                        lugar: proxima.sucursal,
                      ),
                      TextButton(
                        onPressed: () => onIr(PestanaCuenta.reservas),
                        child: const Text('Ver en mis reservas'),
                      ),
                    ],
                  ),
                ]
              : [
                  const SizedBox(height: 6),
                  const Text(
                    'Nada agendado por ahora',
                    style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Elige tu próxima $clase y aparta tu lugar.',
                    style: const TextStyle(color: TemaAgendaUno.textoSuave),
                  ),
                  const SizedBox(height: 14),
                  FilledButton(
                    onPressed: () => onIr(PestanaCuenta.reservas),
                    child: Text(esCitas ? 'Agendar una $clase' : 'Reservar'),
                  ),
                ],
        ),
        const SizedBox(height: 12),

        // Lo que pide atención.
        if (ofrecida != null)
          AvisoAtencion(
            'Se liberó un lugar en ${ofrecida.oferta ?? 'tu $clase'}: acéptalo antes de que se ofrezca a alguien más.',
            onTap: () => onIr(PestanaCuenta.reservas),
          ),
        if (firmar > 0)
          AvisoAtencion(
            firmar == 1
                ? 'Tienes 1 documento por firmar'
                : 'Tienes $firmar documentos por firmar',
            onTap: () => onIr(PestanaCuenta.expediente),
          ),
        if (pagar > 0)
          AvisoAtencion(
            pagar == 1
                ? 'Tienes 1 pago pendiente'
                : 'Tienes $pagar pagos pendientes',
            onTap: () => onIr(PestanaCuenta.pagos),
          ),

        // Accesos directos.
        RejillaAccesos([
          TarjetaAcceso(
            icono: Icons.event_available_outlined,
            titulo: 'Reservar',
            tono: TonosAcceso.reservar,
            valor: sesion?.esCitas ?? false
                ? 'Agenda tu próxima $clase'
                : (disponibles == 1
                      ? '1 $clase disponible'
                      : '$disponibles $clases disponibles'),
            onTap: () => onIr(PestanaCuenta.reservas),
          ),
          TarjetaAcceso(
            icono: Icons.view_list_outlined,
            titulo: 'Mis reservas',
            tono: TonosAcceso.reservas,
            valor: proximas.isEmpty
                ? 'Sin reservas próximas'
                : (proximas.length == 1
                      ? '1 próxima'
                      : '${proximas.length} próximas'),
            onTap: () => onIr(PestanaCuenta.reservas),
          ),
          TarjetaAcceso(
            icono: Icons.local_offer_outlined,
            titulo: 'Mis créditos',
            tono: TonosAcceso.creditos,
            valor: creditos,
            onTap: () => onIr(PestanaCuenta.pagos),
          ),
          TarjetaAcceso(
            icono: Icons.payments_outlined,
            titulo: 'Pagos',
            tono: TonosAcceso.pagos,
            valor: pagar > 0
                ? (pagar == 1 ? '1 por pagar' : '$pagar por pagar')
                : 'Al corriente',
            atencion: pagar > 0,
            onTap: () => onIr(PestanaCuenta.pagos),
          ),
          TarjetaAcceso(
            icono: Icons.qr_code_2,
            titulo: 'Pase de entrada',
            tono: TonosAcceso.pase,
            valor: 'Muéstralo al llegar',
            onTap: () => showModalBottomSheet<void>(
              context: context,
              showDragHandle: true,
              builder: (_) => const PaseSheet(),
            ),
          ),
          TarjetaAcceso(
            icono: Icons.folder_outlined,
            titulo: 'Expediente',
            tono: TonosAcceso.expediente,
            valor: firmar > 0
                ? (firmar == 1
                      ? '1 documento por firmar'
                      : '$firmar documentos por firmar')
                : 'Documentos del negocio',
            atencion: firmar > 0,
            onTap: () => onIr(PestanaCuenta.expediente),
          ),
        ]),
      ],
    );
  }

  /// Su primer nombre para el saludo.
  static String _primerNombre(Sesion? sesion) {
    final nombre = (sesion?.nombrePila ?? sesion?.nombre ?? '').trim();
    return nombre.isEmpty ? '' : nombre.split(RegExp(r'\s+')).first;
  }

  /// "Ilimitado", "6 créditos" o "Sin paquete activo" (1000 unidades = 1).
  static String _creditos(List<DerechoMiembro> derechos) {
    if (derechos.isEmpty) {
      return 'Sin paquete activo';
    }
    if (derechos.any((d) => d.ilimitado && d.pausaHasta == null)) {
      return 'Ilimitado';
    }
    final n = derechos.fold<int>(0, (a, d) => a + d.creditosDisponibles);
    return n == 1 ? '1 crédito' : '$n créditos';
  }
}
