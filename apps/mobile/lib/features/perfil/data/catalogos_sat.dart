/// Catálogos del SAT para los datos de facturación (CFDI 4.0), los mismos que la
/// web (`apps/web/src/lib/catalogosSat.ts`) y el API (`CatalogosSat`): la clave es
/// lo que se guarda; la descripción, la oficial, para elegir sin saberse las claves.
library;

/// A quién le corresponde una clave: persona física, moral o ambas.
enum TipoPersona { fisica, moral }

/// Una clave del catálogo, su descripción y a quién le corresponde.
class ClaveSat {
  const ClaveSat(this.clave, this.descripcion, this.personas);

  final String clave;
  final String descripcion;
  final List<TipoPersona> personas;

  /// «626 · Régimen Simplificado de Confianza».
  String get etiqueta => '$clave · $descripcion';
}

const _fisica = [TipoPersona.fisica];
const _moral = [TipoPersona.moral];
const _ambas = [TipoPersona.fisica, TipoPersona.moral];

/// c_RegimenFiscal.
const regimenesFiscales = <ClaveSat>[
  ClaveSat('601', 'General de Ley Personas Morales', _moral),
  ClaveSat('603', 'Personas Morales con Fines no Lucrativos', _moral),
  ClaveSat(
    '605',
    'Sueldos y Salarios e Ingresos Asimilados a Salarios',
    _fisica,
  ),
  ClaveSat('606', 'Arrendamiento', _fisica),
  ClaveSat('607', 'Régimen de Enajenación o Adquisición de Bienes', _fisica),
  ClaveSat('608', 'Demás ingresos', _fisica),
  ClaveSat(
    '610',
    'Residentes en el Extranjero sin Establecimiento Permanente en México',
    _ambas,
  ),
  ClaveSat('611', 'Ingresos por Dividendos (socios y accionistas)', _fisica),
  ClaveSat(
    '612',
    'Personas Físicas con Actividades Empresariales y Profesionales',
    _fisica,
  ),
  ClaveSat('614', 'Ingresos por intereses', _fisica),
  ClaveSat('615', 'Régimen de los ingresos por obtención de premios', _fisica),
  ClaveSat('616', 'Sin obligaciones fiscales', _fisica),
  ClaveSat(
    '620',
    'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
    _moral,
  ),
  ClaveSat('621', 'Incorporación Fiscal', _fisica),
  ClaveSat(
    '622',
    'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
    _moral,
  ),
  ClaveSat('623', 'Opcional para Grupos de Sociedades', _moral),
  ClaveSat('624', 'Coordinados', _moral),
  ClaveSat(
    '625',
    'Régimen de las Actividades Empresariales con ingresos a través de '
        'Plataformas Tecnológicas',
    _fisica,
  ),
  ClaveSat('626', 'Régimen Simplificado de Confianza', _ambas),
];

/// c_UsoCFDI.
const usosCfdi = <ClaveSat>[
  ClaveSat('G01', 'Adquisición de mercancías', _ambas),
  ClaveSat('G02', 'Devoluciones, descuentos o bonificaciones', _ambas),
  ClaveSat('G03', 'Gastos en general', _ambas),
  ClaveSat('I01', 'Construcciones', _ambas),
  ClaveSat('I02', 'Mobiliario y equipo de oficina por inversiones', _ambas),
  ClaveSat('I03', 'Equipo de transporte', _ambas),
  ClaveSat('I04', 'Equipo de cómputo y accesorios', _ambas),
  ClaveSat('I05', 'Dados, troqueles, moldes, matrices y herramental', _ambas),
  ClaveSat('I06', 'Comunicaciones telefónicas', _ambas),
  ClaveSat('I07', 'Comunicaciones satelitales', _ambas),
  ClaveSat('I08', 'Otra maquinaria y equipo', _ambas),
  ClaveSat(
    'D01',
    'Honorarios médicos, dentales y gastos hospitalarios',
    _fisica,
  ),
  ClaveSat('D02', 'Gastos médicos por incapacidad o discapacidad', _fisica),
  ClaveSat('D03', 'Gastos funerales', _fisica),
  ClaveSat('D04', 'Donativos', _fisica),
  ClaveSat(
    'D05',
    'Intereses reales efectivamente pagados por créditos hipotecarios '
        '(casa habitación)',
    _fisica,
  ),
  ClaveSat('D06', 'Aportaciones voluntarias al SAR', _fisica),
  ClaveSat('D07', 'Primas por seguros de gastos médicos', _fisica),
  ClaveSat('D08', 'Gastos de transportación escolar obligatoria', _fisica),
  ClaveSat(
    'D09',
    'Depósitos en cuentas para el ahorro, primas que tengan como base planes '
        'de pensiones',
    _fisica,
  ),
  ClaveSat('D10', 'Pagos por servicios educativos (colegiaturas)', _fisica),
  ClaveSat('S01', 'Sin efectos fiscales', _ambas),
  ClaveSat('CP01', 'Pagos', _ambas),
  ClaveSat('CN01', 'Nómina', _fisica),
];

/// La forma de un RFC (3 o 4 letras, la fecha y la homoclave).
final _formaRfc = RegExp(r'^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$');

/// Persona física o moral según el RFC: 12 caracteres es moral y 13 física.
/// Mientras no tenga una forma válida, no se sabe (null).
TipoPersona? tipoPersonaDeRfc(String rfc) {
  final limpio = rfc.trim().toUpperCase();
  if (!_formaRfc.hasMatch(limpio)) {
    return null;
  }
  return limpio.length == 12 ? TipoPersona.moral : TipoPersona.fisica;
}

/// Las claves que le corresponden a ese tipo de persona (sin tipo conocido, todas).
/// La [actual] se conserva aunque no le corresponda: el servidor dice por qué.
List<ClaveSat> clavesPara(
  List<ClaveSat> catalogo,
  TipoPersona? tipo, {
  String? actual,
}) => [
  for (final c in catalogo)
    if (tipo == null || c.personas.contains(tipo) || c.clave == actual) c,
];
