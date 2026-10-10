import 'package:flutter/services.dart' show appFlavor;

/// Las dos apps oficiales que salen de este mismo código (ADR 0111): AgendaUno
/// (negocios de clases) y TurnoUno (negocios de citas). Cada una es una compilación
/// distinta, con su nombre, su id en las tiendas y el dominio de su producto; la
/// API, las pantallas y los datos son los mismos.
///
/// El producto lo decide el sabor (`--flavor turnouno`) o, donde aún no hay sabores
/// (iOS sin sus esquemas), `--dart-define=PRODUCTO=turnouno`. Sin ninguno, AgendaUno.
class ProductoApp {
  const ProductoApp._({
    required this.clave,
    required this.nombre,
    required this.dominio,
    required this.idAndroid,
    required this.modalidad,
  });

  /// Como lo nombra la API (`producto` de /marca y /yo, `X-App-Producto`).
  final String clave;

  /// La marca («AgendaUno»).
  final String nombre;

  /// Dominio del producto: su web y, en producción, su API.
  final String dominio;

  /// `applicationId` de la app oficial en Google Play.
  final String idAndroid;

  /// La modalidad de sus negocios (`clases` o `citas`, ADR 0104).
  final String modalidad;

  static const agendaUno = ProductoApp._(
    clave: 'agendauno',
    nombre: 'AgendaUno',
    dominio: 'agendauno.mx',
    idAndroid: idAndroidAgendaUno,
    modalidad: 'clases',
  );

  static const turnoUno = ProductoApp._(
    clave: 'turnouno',
    nombre: 'TurnoUno',
    dominio: 'turnouno.mx',
    idAndroid: idAndroidTurnoUno,
    modalidad: 'citas',
  );

  static const todos = [agendaUno, turnoUno];

  /// Ids de Google Play (los `applicationId` de android/app/build.gradle.kts: si
  /// cambian allá, cambian aquí).
  static const idAndroidAgendaUno = 'com.agendauno.app';
  static const idAndroidTurnoUno = 'com.turnouno.app';

  static const String _definido = String.fromEnvironment('PRODUCTO');

  /// ¿Esta compilación es TurnoUno?
  static const bool esTurnoUno =
      _definido == 'turnouno' || (_definido == '' && appFlavor == 'turnouno');

  /// El producto de esta compilación.
  static const ProductoApp actual = esTurnoUno ? turnoUno : agendaUno;

  /// El producto con esa clave (la de la API), o null.
  static ProductoApp? deClave(String? clave) {
    for (final p in todos) {
      if (p.clave == clave) {
        return p;
      }
    }
    return null;
  }

  /// El otro producto: a dónde mandar a quien abrió la app equivocada.
  ProductoApp get otro => identical(this, turnoUno) ? agendaUno : turnoUno;

  /// URL de producción de su API (la misma de su web).
  String get urlProduccion => 'https://$dominio';

  @override
  String toString() => clave;
}
