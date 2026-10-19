/// Horas y días en la zona del negocio o de su sucursal, sin un paquete de zonas
/// (ADR 0166): con el desfase con UTC que manda el API (`zona_desfase_min` del
/// negocio al entrar, `utc_offset_min` de cada sesión) o con la hora local que ya
/// viene calculada (`inicia_local`, sin zona). Nunca con la zona del teléfono,
/// salvo con un API anterior que no manda ninguno de los dos.
abstract final class HoraNegocio {
  /// La hora de pared de [instante] en una zona con ese desfase con UTC (minutos):
  /// sus campos (año… segundo) son la fecha y la hora allá. Sin desfase (API
  /// anterior), la del teléfono.
  static DateTime enDesfase(DateTime instante, int? desfaseMin) {
    if (desfaseMin == null) {
      return instante.toLocal();
    }
    final u = instante.toUtc().add(Duration(minutes: desfaseMin));
    return DateTime(u.year, u.month, u.day, u.hour, u.minute, u.second);
  }

  /// Una fecha del API como hora de pared: un instante (con zona, p. ej.
  /// `…T15:00:00+00:00`) se pasa a la zona con [desfaseMin]; un texto sin zona
  /// (`AAAA-MM-DDTHH:MM[:SS]`, la hora local que ya calculó el API) se toma tal
  /// cual. Null si no vino o no se entiende.
  static DateTime? pared(String? iso, {int? desfaseMin}) {
    final f = iso == null ? null : DateTime.tryParse(iso);
    if (f == null) {
      return null;
    }
    // `DateTime.tryParse` da UTC solo cuando el texto trae zona.
    return f.isUtc ? enDesfase(f, desfaseMin) : f;
  }

  /// El desfase (minutos) de la zona en la que [instante] se ve como [pared].
  static int desfaseEntre(DateTime pared, DateTime instante) =>
      (DateTime.utc(
                pared.year,
                pared.month,
                pared.day,
                pared.hour,
                pared.minute,
                pared.second,
              ).difference(instante.toUtc()).inSeconds /
              60)
          .round();

  /// El desfase (minutos) con UTC del teléfono en [instante].
  static int desfaseTelefono(DateTime instante) =>
      instante.toLocal().timeZoneOffset.inMinutes;

  /// Lo que se avisa junto a las horas cuando el teléfono está en otra zona que
  /// la sucursal (las horas son siempre las de la sucursal), a partir de los
  /// nombres de las sucursales que están en otra zona: «Hora de Roma Norte»,
  /// «Hora de la sucursal» si no se sabe cuál o «Hora de cada sucursal» si son
  /// varias. Null si ninguna lo está.
  static String? etiqueta(Iterable<String?> sucursalesEnOtraZona) {
    final nombres = sucursalesEnOtraZona.map((s) => s?.trim() ?? '').toSet();
    if (nombres.isEmpty) {
      return null;
    }
    if (nombres.length > 1) {
      return 'Hora de cada sucursal';
    }
    return nombres.single.isEmpty
        ? 'Hora de la sucursal'
        : 'Hora de ${nombres.single}';
  }
}
