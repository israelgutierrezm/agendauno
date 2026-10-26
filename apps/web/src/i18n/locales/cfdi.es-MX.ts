// El CFDI de cada negocio (ADR 0212): descargar, reenviar, su alta en FacturAPI, el
// IVA de lo que vende y Mis facturas.
export default {
  pdf: "PDF",
  xml: "XML",
  descargarPdf: "Descargar el PDF de la factura {uuid}",
  descargarXml: "Descargar el XML de la factura {uuid}",
  enviar: "Enviar por correo",
  confirmarEnvio: "¿Mandar la factura a {correo}?",
  confirmarEnvioAceptar: "Enviar factura",
  enviada: "La factura va en camino a {correo}.",
  alta: {
    lista: "Tu negocio ya factura con su propio sello.",
    pendiente: "Falta un paso para facturar:",
    firmarCarta:
      "Firma la carta manifiesto con tu e.firma (FIEL) en el portal de FacturAPI; autoriza a timbrar tus facturas.",
    abrirPortal: "Firmar la carta manifiesto",
    revisar: "Revisar de nuevo",
    revisado: "Listo: revisamos tu alta.",
    error: "FacturAPI respondió: {motivo}",
  },
  iva: {
    etiqueta: "IVA al facturar",
    general: "El del negocio",
    exento: "Exento",
    cero: "Tasa 0 %",
    ayuda:
      "Exento, por ejemplo, para servicios médicos. Si no sabes, déjalo como el del negocio.",
    claveSat: "Clave del SAT (opcional)",
    claveSatAyuda:
      "Ocho dígitos del catálogo de productos y servicios del SAT. Vacío, la de siempre.",
  },
  // La llave de usuario de FacturAPI en el superadmin (ADR 0212).
  usuario: {
    titulo: "FacturAPI: alta de los negocios",
    subtitulo:
      "La llave de usuario de la cuenta (sk_user_…): con ella cada negocio se da de alta con su sello y factura con su propia llave.",
    llave: "Llave de usuario de FacturAPI",
    confirmarQuitar:
      "¿Quitar la llave de usuario? Ningún negocio nuevo podrá darse de alta para facturar hasta que pongas otra.",
  },
  // Paquetes de mensajes de WhatsApp que venden los negocios (ADR 0186).
  whatsappPaquetes: {
    etiqueta: "Paquetes de mensajes de WhatsApp",
    ayuda:
      "Cuántos mensajes trae cada paquete que compran los negocios, separados por comas.",
  },
  mias: {
    titulo: "Mis facturas",
    vacio: "Aún no tienes facturas.",
    cancelada: "Cancelada",
  },
};
