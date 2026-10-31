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
  // La timbró el RFC de antes (ADR 0228): se descarga, se reenvía y se cancela.
  rfcAnterior: "Timbrada con tu RFC anterior ({rfc})",
  // Lo que no se factura de una venta (ADR 0228).
  venta: {
    descuento: "Ya lleva {monto} de descuento, repartidos entre los conceptos.",
    devuelto: "No incluye {monto} ya devueltos.",
  },
  alta: {
    lista: "Tu negocio ya factura con su propio sello.",
    pendiente: "Falta un paso para facturar:",
    firmarCarta:
      "Firma la carta manifiesto con tu e.firma (FIEL) en el portal de FacturAPI; autoriza a timbrar tus facturas.",
    abrirPortal: "Firmar la carta manifiesto",
    revisar: "Revisar de nuevo",
    revisado: "Listo: revisamos tu alta.",
    error: "FacturAPI respondió: {motivo}",
    // El sello que tiene FacturAPI es de otro RFC (ADR 0228): no se factura.
    selloDeOtroRfc:
      "El último sello digital que subiste es del RFC {rfcSello} y tus datos fiscales dicen {rfc}. Sube el sello de tu RFC o corrige tu RFC: mientras, no se factura.",
    // Al capturar otro RFC (ADR 0228).
    cambioDeRfc:
      "Otro RFC es otro emisor: al guardar tendrás que subir su sello digital. Lo que ya timbraste se sigue descargando, reenviando y cancelando; sus notas de crédito y sustitutas ya no se hacen desde aquí.",
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
    // En cada concepto de una factura (ADR 0228).
    concepto: "IVA",
    // Una venta de antes de guardar su foto fiscal (ADR 0228).
    delCatalogo:
      "Esta venta es de antes de que se guardara cómo se factura cada cosa: el IVA y las claves salen del catálogo de hoy. Revísalos antes de timbrar.",
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
    periodo: "Periodo de las facturas",
    error: "No pudimos cargar tus facturas.",
  },
};
