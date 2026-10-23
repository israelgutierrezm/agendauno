import 'dart:convert';

import '../../../core/formato.dart';
import '../../auth/data/sesion.dart';
import '../../cuenta/data/cuenta_models.dart';
import '../../cuenta/data/eventos_cuenta.dart';

/// Las claves que comparten la app y el widget de la pantalla de inicio (Android:
/// las preferencias de home_widget; iOS: el UserDefaults del App Group). Si cambian
/// aquí, cambian en `android/.../WidgetProximo.kt` y en `ios/ProximoWidget/`.
abstract final class ClavesWidget {
  /// El nombre del negocio (encabezado).
  static const negocio = 'au_negocio';

  /// Sus próximas reservas o citas (JSON, hasta [ProximaWidget.maximo]): el widget
  /// muestra la primera que aún no termina, así no se queda con una que ya pasó.
  static const proximas = 'au_proximas';

  /// «Mis créditos» o «Mi bono» y lo que dice su plan («6 créditos · vence el …»).
  static const planTitulo = 'au_plan_titulo';
  static const plan = 'au_plan';

  /// Sin nada agendado: qué decir y qué invitar a hacer.
  static const vacio = 'au_vacio';
  static const accion = 'au_accion';

  static const todas = [negocio, proximas, planTitulo, plan, vacio, accion];
}

/// Una reserva o cita como la muestra el widget: qué, cuándo y dónde. Solo lo que
/// el cliente ya ve en su Inicio: ni con quién ni para quién.
class ProximaWidget {
  const ProximaWidget({
    required this.etiqueta,
    required this.titulo,
    required this.cuando,
    required this.terminaMs,
    this.donde,
    this.aviso,
  });

  /// Cuántas se le pasan al widget.
  static const maximo = 3;

  /// «Tu próxima clase» o «Tu próxima cita».
  final String etiqueta;

  /// La clase o el servicio.
  final String titulo;

  /// «jue 25 sep · 09:30», en la hora de su sede.
  final String cuando;

  /// La sucursal, si se sabe.
  final String? donde;

  /// «Pendiente de pago», si falta pagarla para confirmarla.
  final String? aviso;

  /// Cuándo termina (milisegundos desde 1970, UTC): después, el widget pasa a la
  /// siguiente.
  final int terminaMs;

  Map<String, Object?> aJson() => {
    'etiqueta': etiqueta,
    'titulo': titulo,
    'cuando': cuando,
    'donde': donde,
    'aviso': aviso,
    'termina': terminaMs,
  };
}

/// Lo que muestra el widget de inicio del cliente, armado con lo que la app ya
/// cargó de su cuenta (lo mismo que su Inicio): sus próximas reservas o citas y
/// su saldo o plan, si el negocio lo usa.
class DatosWidgetInicio {
  const DatosWidgetInicio({
    required this.proximas,
    required this.vacio,
    required this.accion,
    this.negocio,
    this.planTitulo,
    this.plan,
  });

  final String? negocio;
  final List<ProximaWidget> proximas;
  final String? planTitulo;
  final String? plan;
  final String vacio;
  final String accion;

  /// Sin una clase o cita con hora de fin, se da por terminada a la hora.
  static const _duracionSupuesta = Duration(hours: 1);

  /// Con su cuenta y su sesión, en [ahora]: las confirmadas o por pagar que aún no
  /// terminan, de la más cercana a la más lejana (como la tarjeta de su Inicio).
  factory DatosWidgetInicio.desdeCuenta(
    MiCuenta cuenta,
    Sesion sesion, {
    required DateTime ahora,
  }) {
    final terminos = sesion.terminologia;
    final clase = terminos.sesion.toLowerCase();
    final esCitas = sesion.esCitas;
    final proximas = <ProximaWidget>[];
    for (final r in proximasReservas(cuenta)) {
      if (proximas.length == ProximaWidget.maximo) {
        break;
      }
      final inicia = DateTime.tryParse(r.iniciaEn ?? '');
      if (inicia == null ||
          (r.estado != 'confirmada' && r.estado != 'pendiente_pago')) {
        continue;
      }
      final termina =
          DateTime.tryParse(r.terminaEn ?? '') ?? inicia.add(_duracionSupuesta);
      if (!termina.isAfter(ahora)) {
        continue;
      }
      proximas.add(
        ProximaWidget(
          etiqueta: r.esCita ? 'Tu próxima cita' : 'Tu próxima $clase',
          titulo: r.oferta ?? (r.esCita ? 'Cita' : terminos.sesion),
          cuando: Formato.fechaHora(
            r.iniciaEn,
            desfaseMin: r.desfaseMin ?? sesion.desfaseNegocioMin,
          ),
          donde: r.sucursal,
          aviso: r.estado == 'pendiente_pago' ? 'Pendiente de pago' : null,
          terminaMs: termina.millisecondsSinceEpoch,
        ),
      );
    }
    final plan = resumenPlan(cuenta, esCitas: esCitas);
    return DatosWidgetInicio(
      negocio: sesion.estudioNombre,
      proximas: proximas,
      planTitulo: plan == null ? null : (esCitas ? 'Mi bono' : 'Mis créditos'),
      plan: plan,
      vacio: 'Nada agendado por ahora',
      accion: esCitas
          ? 'Agenda tu próxima $clase'
          : 'Reserva tu próxima $clase',
    );
  }

  /// Lo que se guarda para el widget nativo: textos (las próximas, en JSON). Un
  /// valor null borra la clave.
  Map<String, String?> aClaves() => {
    ClavesWidget.negocio: negocio,
    ClavesWidget.proximas: jsonEncode([for (final p in proximas) p.aJson()]),
    ClavesWidget.planTitulo: planTitulo,
    ClavesWidget.plan: plan,
    ClavesWidget.vacio: vacio,
    ClavesWidget.accion: accion,
  };

  /// Sin sesión de cliente: nada guardado (el widget invita a abrir la app).
  static Map<String, String?> get sinSesion => {
    for (final c in ClavesWidget.todas) c: null,
  };
}
