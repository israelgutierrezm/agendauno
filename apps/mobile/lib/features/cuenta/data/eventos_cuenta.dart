import '../../../core/calendario/calendario.dart';
import '../../../core/formato.dart';
import '../../../core/hora_negocio.dart';
import 'cuenta_models.dart';

/// Estados de una reserva que siguen en pie (las que ve en su calendario).
const estadosProximos = {
  'confirmada',
  'pendiente_pago',
  'ofrecida',
  'en_espera',
};

/// Sus reservas próximas, de la más cercana a la más lejana.
List<ReservaMiembro> proximasReservas(MiCuenta c) =>
    c.reservas
        .where((r) => r.iniciaEn != null && estadosProximos.contains(r.estado))
        .toList()
      ..sort((a, b) => a.iniciaEn!.compareTo(b.iniciaEn!));

/// Las clases a las que aún puede entrar (no reservadas por él).
/// Las que puede reservar: sin reservar aún y que su plan incluye (o de pago).
List<ClaseMiembro> clasesDisponibles(MiCuenta c) => c.clases
    .where(
      (x) =>
          x.iniciaEn != null &&
          !c.sesionesReservadas.contains(x.id) &&
          x.reservable,
    )
    .toList();

/// "3 de 10 lugares" o "Llena", con los lugares libres que cuenta el servidor.
String lugaresTexto(ClaseMiembro c) {
  if (c.llena) {
    return 'Llena';
  }
  final clase = c.clase;
  return clase?.capacidad == null || clase?.libres == null
      ? ''
      : '${clase!.libres} de ${clase.capacidad} lugares';
}

/// Lo que pinta su calendario: sus reservas resaltadas y las clases disponibles
/// (las del periodo que se ve, si se pasan). El id de cada evento es el de la
/// reserva o el de la clase. En los días y las horas de su sede (`utc_offset_min`
/// de cada una) o, si el API no lo manda, en los del negocio ([desfaseMin], el de
/// la sesión); nunca en los del teléfono.
List<EventoCal> eventosDeCuenta(
  MiCuenta c, {
  List<ClaseMiembro>? clases,
  int? desfaseMin,
}) {
  DateTime? fecha(String? iso, int? deLaSede) =>
      HoraNegocio.pared(iso, desfaseMin: deLaSede ?? desfaseMin);
  String detalle(String? sucursal, String? instructor) => [
    sucursal,
    if (instructor != null) 'con $instructor',
  ].whereType<String>().join(' · ');

  return [
    for (final r in proximasReservas(c))
      EventoCal(
        id: r.id,
        titulo: r.oferta ?? '—',
        inicio: fecha(r.iniciaEn, r.desfaseMin)!,
        fin: fecha(r.terminaEn, r.desfaseMin),
        detalle: detalle(r.sucursal, r.instructor),
        estado: r.estado == 'confirmada' ? 'Reservada' : r.estadoTexto,
        tono: r.estado == 'confirmada' ? TonoEvento.primario : TonoEvento.aviso,
        destacado: true,
      ),
    for (final x in (clases ?? c.clases).where(
      (x) => x.iniciaEn != null && !c.sesionesReservadas.contains(x.id),
    ))
      EventoCal(
        id: x.id,
        titulo: x.oferta ?? '—',
        inicio: fecha(x.iniciaEn, x.desfaseMin)!,
        fin: fecha(x.terminaEn, x.desfaseMin),
        detalle: detalle(x.sucursal, x.instructor),
        // Si su plan no la incluye, eso es lo primero que se dice.
        estado: x.reservable ? lugaresTexto(x) : x.cobertura!.texto,
        tono: !x.reservable
            ? TonoEvento.suave
            : x.llena
            ? TonoEvento.aviso
            : TonoEvento.suave,
      ),
  ]..sort((a, b) => a.inicio.compareTo(b.inicio));
}

/// Lo que dice su plan en el Inicio y en el widget de inicio: «6 créditos · vence
/// el 14 nov», «Ilimitado», «En pausa hasta el …» o «Sin paquete activo». Lo
/// vigente (según el estado que da el servidor) suma; lo vencido es historial.
/// Null si no aplica: el negocio no usa saldo para él, o en citas sin bono.
String? resumenPlan(MiCuenta c, {required bool esCitas}) {
  final tienePlan = c.derechos.isNotEmpty;
  if (!((c.portal?.creditos ?? tienePlan) && (tienePlan || !esCitas))) {
    return null;
  }
  final vigentes = c.derechos.where((d) => d.vigente).toList();
  final creditos = vigentes.isEmpty
      ? estadoSinPlanVigente(c.derechos)
      : DerechoMiembro.resumenCreditos(vigentes);
  final vence = primerVencimiento(vigentes);
  return vence != null && tienePlan
      ? '$creditos · vence el ${Formato.diaMes(vence)}'
      : creditos;
}

/// Sin plan vigente, por qué: en pausa, suspendido o por empezar (en ese orden).
String estadoSinPlanVigente(List<DerechoMiembro> derechos) {
  DerechoMiembro? con(String estado) =>
      derechos.where((d) => d.estado == estado).firstOrNull;
  final pausado = con('pausado');
  if (pausado != null) {
    return pausado.pausaHasta == null
        ? 'En pausa'
        : 'En pausa hasta el ${Formato.diaMes(pausado.pausaHasta)}';
  }
  if (con('suspendido') != null) {
    return 'Suspendido: paga para reactivarlo';
  }
  final porEmpezar = con('por_empezar');
  if (porEmpezar?.desde != null) {
    return 'Empieza el ${Formato.diaMes(porEmpezar!.desde)}';
  }
  return 'Sin paquete activo';
}

/// Lo que antes vence de su plan (AAAA-MM-DD), si vence.
String? primerVencimiento(List<DerechoMiembro> derechos) {
  final fechas = derechos.map((d) => d.vence).whereType<String>().toList()
    ..sort();
  return fechas.firstOrNull;
}
