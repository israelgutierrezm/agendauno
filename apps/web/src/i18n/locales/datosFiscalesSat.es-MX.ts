/**
 * Datos fiscales: el régimen se elige del catálogo del SAT (clave y descripción, con
 * buscador) y arriba se ve, como lista, lo que falta para timbrar facturas.
 */
export default {
  // Al emitir una factura (FacturasView): el receptor y los catálogos del CFDI.
  factura: {
    regimenReceptor: "Régimen fiscal del receptor",
    regimenReceptorPh: "Busca por clave o nombre",
    regimenReceptorAyuda:
      "El de su Constancia de Situación Fiscal. El SAT lo pide en el CFDI 4.0.",
    usoPh: "Busca por clave o uso, p. ej. G03 o gastos",
    usoAyuda:
      "Lo decide quien recibe la factura; el más común es G03 · Gastos en general.",
    paraElRfc: "Para su RFC",
    otrosUsos: "Otros usos",
    usoNoCorresponde:
      "Ese uso es solo para personas físicas y el RFC es de persona moral: el SAT lo rechazará.",
    formaPh: "Busca por clave o forma, p. ej. 03 o transferencia",
  },
  regimen: {
    etiqueta: "Régimen fiscal",
    placeholder: "Busca por clave o nombre, p. ej. 626 o RESICO",
    ayuda:
      "El que aparece en tu Constancia de Situación Fiscal. Primero salen los que corresponden a tu RFC.",
    paraTuRfc: "Para tu RFC",
    otros: "Otros regímenes",
    personas: {
      fisica: "Persona física",
      moral: "Persona moral",
      ambas: "Física o moral",
    },
    noCorresponde: {
      fisica:
        "Este régimen es de personas morales y tu RFC es de persona física: el SAT no timbrará con él.",
      moral:
        "Este régimen es de personas físicas y tu RFC es de persona moral: el SAT no timbrará con él.",
    },
  },
  rfcAyuda: "12 caracteres si es persona moral, 13 si es persona física.",
  codigoPostalAyuda: "El de tu domicilio fiscal, a 5 dígitos.",
  guardado: "Datos fiscales de «{razon}» guardados.",
  // Lo que se necesita para timbrar, como lista.
  requisitos: {
    titulo: "Para timbrar facturas",
    listo: "Ya tienes todo para timbrar facturas.",
    faltan: "Te falta 1 cosa para timbrar. | Te faltan {n} cosas para timbrar.",
    hecho: "Listo",
    falta: "Falta",
    razonRfc: "Razón social y RFC",
    regimen: "Régimen fiscal del catálogo del SAT",
    codigoPostal: "Código postal del domicilio fiscal",
    sellos: "Sello digital (CSD): certificado .cer, llave .key y su contraseña",
    timbres: "Timbres disponibles",
    timbresCuantos: "{n} disponibles",
    verTimbres: "Ver timbres",
  },
};
