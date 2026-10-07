import '../../../core/calendario/calendario.dart';
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
/// reserva o el de la clase.
List<EventoCal> eventosDeCuenta(MiCuenta c, {List<ClaseMiembro>? clases}) {
  DateTime? fecha(String? iso) =>
      iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  String detalle(String? sucursal, String? instructor) => [
    sucursal,
    if (instructor != null) 'con $instructor',
  ].whereType<String>().join(' · ');

  return [
    for (final r in proximasReservas(c))
      EventoCal(
        id: r.id,
        titulo: r.oferta ?? '—',
        inicio: fecha(r.iniciaEn)!,
        fin: fecha(r.terminaEn),
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
        inicio: fecha(x.iniciaEn)!,
        fin: fecha(x.terminaEn),
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
