/**
 * Textos del modelo comercial (ADR 0107): plan de los negocios de citas, precios en
 * dólares cobrados en pesos en México, domiciliación de la tarjeta, timbres para
 * facturar y lo que no incluye un nivel. Se montan bajo `suscripcion` en es-MX.
 */
export default {
  niveles: {
    individual: "Individual",
    premium: "Premium",
    pro: "Pro",
  },
  periodicidad: {
    mensual: "Mensual",
    anual: "Anual",
    anualAyuda: "Pagas 10 meses y te damos 2 de cortesía.",
  },
  plan: {
    titulo: "Tu plan",
    resumen: "{nivel} · {n} profesional | {nivel} · {n} profesionales",
    cubiertoHasta: "Pagado hasta el {fecha}.",
    porAdelantado:
      "Se cobra por adelantado, por los profesionales que contratas.",
    sinElegir:
      "Aún no eliges plan. Al terminar la prueba quedarás en {nivel} con {n} profesional. | Aún no eliges plan. Al terminar la prueba quedarás en {nivel} con {n} profesionales.",
    pruebaPro: "En la prueba gratis tienes todas las funciones de Pro.",
    siguiente:
      "Desde el siguiente periodo: {nivel} · {n} profesionales, {periodicidad}.",
    profesionales: "{actuales} de {limite} profesionales en uso",
    cambiar: "Cambiar plan",
    cerrar: "Cerrar",
    elegir: "Elegir",
    actual: "Tu plan",
    guardando: "Guardando…",
    porMes: "al mes",
    porAno: "al año",
    porProfesional: "Profesionales",
    menos: "Uno menos",
    mas: "Uno más",
    aproximado: "≈ {monto} al tipo de cambio de hoy",
    tipoCambio:
      "Precios en dólares. Te cobramos en pesos al tipo de cambio del día del cobro (hoy 1 USD = {valor} MXN).",
    enDolares: "Precios en dólares, más impuestos.",
    masDe: "¿Más de {n} profesionales? Pide una cotización:",
    cotizarCorreo: "escríbenos a {correo}",
    cotizarWhatsApp: "o por WhatsApp",
    aplicaAhora: "Listo, tu plan cambió.",
    aplicaAhoraCobro:
      "Listo, tu plan cambió. Te cobramos {monto} por los días que faltan del periodo.",
    aplicaAhoraPagado:
      "Listo, tu plan cambió. Cobramos {monto} a tu tarjeta por los días que faltan.",
    aplicaSiguiente: "Listo. El cambio aplica desde tu siguiente periodo.",
    subir:
      "Subir se cobra al momento por los días que faltan; bajar aplica desde el siguiente periodo.",
  },
  clases: {
    titulo: "Por alumnos activos",
    ayuda:
      "Cuenta a quien reservó una clase o compró algo en el mes. Se cobra al cerrar el mes.",
    masDe: "¿Más de 1,000 alumnos? Pide una cotización:",
  },
  cobro: {
    concepto: {
      renta: "Renta",
      plan: "Plan",
      ajuste: "Cambio de plan",
      timbres: "Timbres",
    },
    cubre: "Del {desde} al {hasta}",
    tipoCambio: "1 USD = {valor} MXN ({fecha})",
    rechazo: "Tu tarjeta no pasó. Volvemos a intentarlo el {fecha}.",
    rechazoFinal: "Tu tarjeta no pasó. Págalo aquí.",
    autenticacion: "Tu banco pide confirmar el pago. Págalo aquí.",
    cancelado: "Cancelado",
  },
  tarjeta: {
    titulo: "Cobro automático",
    ayuda:
      "Guarda una tarjeta y cada cobro de tu suscripción se paga solo. Si no pasa, lo reintentamos a los 3 y a los 7 días.",
    guardar: "Guardar tarjeta",
    cambiar: "Cambiar tarjeta",
    quitar: "Quitar",
    abriendo: "Abriendo…",
    activa: "{marca} terminada en {ultimos4}",
    vence: "Vence {fecha}",
    guardada: "Listo, tu tarjeta quedó guardada para el cobro automático.",
    quitada: "Quitamos tu tarjeta: los siguientes cobros los pagas tú.",
    cancelado: "No se guardó la tarjeta.",
    noDisponible: "El cobro automático aún no está disponible.",
    confirmarQuitar:
      "¿Quitar tu tarjeta? Los siguientes cobros tendrás que pagarlos tú.",
  },
  timbres: {
    titulo: "Timbres para facturar",
    ayuda:
      "Cada factura que emites a tus clientes usa un timbre. Cómpralos en paquetes; no caducan.",
    disponibles: "{n} timbre disponible | {n} timbres disponibles",
    paquete: "{n} timbres",
    precio: "{monto} + IVA",
    comprar: "Comprar",
    comprando: "Abriendo…",
    agotados: "Ya no tienes timbres: compra un paquete para seguir facturando.",
    comprados: "Pago recibido: tus timbres se suman en un momento.",
    movimientos: "Movimientos",
    compra: "Compra",
    consumo: "Factura",
    ajuste: "Ajuste",
  },
  bloqueo: {
    premium: "Esta función está en el plan Premium.",
    pro: "Esta función está en el plan Pro.",
    cambiar: "Cambiar mi plan",
  },
  plataforma: {
    tipoCambio: {
      titulo: "Tipo de cambio",
      ayuda:
        "Con él se cobra en pesos la renta en dólares a los negocios de México. Con el token del Banco de México (Configuración → Datos comerciales) se usa su FIX del día; sin él, el que captures aquí.",
      banxico: "Banco de México conectado",
      sinBanxico: "Sin token del Banco de México: captura el del día",
      ultimo: "1 USD = {valor} MXN · {fecha}",
      fuenteBanxico: "Banco de México",
      fuenteManual: "Capturado",
      sinValor: "Aún no hay tipo de cambio.",
      valor: "Pesos por dólar",
      guardar: "Guardar",
      guardado: "Tipo de cambio guardado.",
    },
    tarifas: {
      moneda: "Moneda",
      ivaExtranjero: "IVA fuera de México %",
      mesesAnual: "Meses que cuesta el anual",
      nivel: "Nivel",
      profesionales: "Profesionales",
      precioMensual: "Precio al mes",
      agregarFila: "Agregar profesional",
      quitarFila: "Quitar el último",
      funciones: "Qué nivel abre cada función",
      funcionesAyuda:
        "Lo que se elige aquí decide lo que cada negocio puede usar y lo que muestran la landing y «Mi suscripción».",
    },
    cuotaMoneda: "Moneda de la cuota",
    otraMoneda:
      "En {moneda}: por cobrar {porCobrar}, vencido {vencido}, cobrado este mes {cobrado}.",
    comercial: {
      titulo: "Datos comerciales",
      ayuda:
        "A dónde se manda a quien pide cotización (más profesionales o alumnos de los que ofrece la tarifa), el tipo de cambio y los paquetes de timbres que se venden.",
      ventasCorreo: "Correo de ventas",
      ventasWhatsApp: "WhatsApp de ventas (con lada)",
      banxico: "Token del Banco de México",
      banxicoGuardado: "Guardado (escribe otro para cambiarlo)",
      banxicoAyuda:
        "Sin token, el tipo de cambio se captura a mano en Tarifas. Se pide gratis en banxico.org.mx.",
      quitar: "Quitar",
      paquetes: "Paquetes de timbres",
      paquetesAyuda:
        "Cuántos timbres trae cada paquete, separados por comas. El precio por timbre está en Parámetros.",
      guardar: "Guardar",
      guardado: "Datos comerciales guardados.",
    },
  },
};
