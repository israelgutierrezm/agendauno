// Moneda y zona horaria del negocio (views/RegionNegocioView.vue, ADR 0099).
export default {
  titulo: "Moneda y zona horaria",
  subtitulo:
    "Tu negocio trabaja con una sola moneda y una zona horaria para sus reportes, cortes y agenda.",
  moneda: {
    titulo: "Moneda",
    etiqueta: "Moneda del negocio",
    ayuda:
      "La de todos tus precios, cobros y reportes. Se elige antes de empezar a cobrar.",
    bloqueada:
      "Ya hay cobros registrados en {moneda}: la moneda ya no se puede cambiar.",
    confirmar:
      "¿Cambiar la moneda a {moneda}? Tus precios conservan su número y pasan a {moneda}.",
  },
  zona: {
    titulo: "Zona horaria",
    etiqueta: "Zona horaria del negocio",
    ayuda:
      "Con ella se cuentan los días de tus reportes y cortes de caja, las vigencias y los horarios. Cada sucursal puede tener la suya.",
  },
  avisos: {
    pasarelas: "Pasarelas de pago en línea",
    facturacion: "Facturación a tus clientes",
    disponible: "Disponible",
    soloPesos: "Solo con pesos mexicanos (MXN)",
    soloPesosMexico: "Solo con pesos mexicanos (MXN) y para negocios en México",
  },
  guardar: "Guardar",
  guardando: "Guardando…",
  guardado: "Listo: se guardó.",
  zonas: {
    America__Mexico_City: "Centro de México (Ciudad de México)",
    America__Cancun: "Quintana Roo (Cancún)",
    America__Merida: "Yucatán (Mérida)",
    America__Monterrey: "Noreste (Monterrey)",
    America__Chihuahua: "Chihuahua",
    America__Mazatlan: "Pacífico (Mazatlán, La Paz)",
    America__Hermosillo: "Sonora (Hermosillo)",
    America__Tijuana: "Noroeste (Tijuana)",
    America__Guatemala: "Guatemala",
    America__Costa_Rica: "Costa Rica",
    America__Santo_Domingo: "República Dominicana",
    America__Bogota: "Colombia (Bogotá)",
    America__Lima: "Perú (Lima)",
    America__Santiago: "Chile (Santiago)",
    America__Argentina__Buenos_Aires: "Argentina (Buenos Aires)",
    America__New_York: "Estados Unidos (Este)",
    America__Los_Angeles: "Estados Unidos (Pacífico)",
    Europe__Madrid: "España (Madrid)",
  },
  // Pantallas que dependen de los pesos mexicanos.
  noDisponible: {
    pasarelasTitulo: "El cobro en línea solo funciona en pesos mexicanos",
    pasarelasDetalle:
      "Tu negocio trabaja en {moneda}. Las pasarelas de pago en línea (Stripe, Mercado Pago, OpenPay) solo están disponibles con pesos mexicanos (MXN). Puedes seguir cobrando en el negocio.",
    facturacionTitulo:
      "La facturación solo funciona en pesos mexicanos y en México",
    facturacionDetalle:
      "Facturar a tus propios clientes (CFDI) solo está disponible para negocios en México que trabajan en pesos mexicanos (MXN).",
    cambiar: "Ver moneda y zona horaria",
  },
};
