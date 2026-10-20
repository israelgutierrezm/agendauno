// Cancelar, sustituir y notas de crédito (components/CancelarFactura.vue,
// components/NotaDeCredito.vue, views/FacturasView.vue; ADR 0180).
export default {
  colAcciones: "Acciones",
  cancelar: "Cancelar",
  notaDeCredito: "Nota de crédito",
  // Estados en la lista.
  cancelada: "Cancelada",
  canceladas: "Canceladas",
  enEspera: "Cancelación en espera del cliente",
  esNota: "Nota de crédito de {folio}",
  sustituye: "Sustituye a {folio}",
  // El diálogo de cancelar.
  titulo: "Cancelar la factura",
  deQuien: "{receptor} · {total} · folio {folio}",
  motivo: "¿Por qué se cancela?",
  motivo02: "Se emitió con errores y no se va a sustituir",
  motivo02Ayuda:
    "Por ejemplo, se facturó de más o a quien no era, y no lleva otra factura en su lugar.",
  motivo03: "La venta no se llevó a cabo",
  motivo03Ayuda: "La operación se deshizo: no hubo venta que facturar.",
  sustituirPregunta: "¿Hay datos mal y la vas a volver a facturar?",
  sustituirla: "Sustitúyela",
  esperaAyuda:
    "Si el cliente debe aceptarla, queda en espera hasta que responda; te avisamos aquí cómo va.",
  volver: "Volver",
  pedir: "Pedir la cancelación",
  pidiendo: "Pidiendo…",
  hecha: "La factura quedó cancelada.",
  enEsperaHecha:
    "Se pidió la cancelación: queda en espera de que el cliente la acepte.",
  // La nota de crédito.
  notaTitulo: "Nota de crédito",
  notaAyuda:
    "Un CFDI de egreso por lo que devolviste, descontaste o bonificaste de esta factura. Queda relacionada con ella.",
  queda: "Quedan {monto} por acreditar.",
  monto: "Monto con IVA",
  descripcion: "Descripción",
  descripcionPh: "Devolución",
  formaPago: "Cómo se le devolvió",
  regimen: "Régimen fiscal del cliente",
  regimenAyuda:
    "La factura no lo guardó y la nota de crédito lo lleva; queda guardado para las siguientes.",
  confirmar: "¿Timbrar una nota de crédito por {monto} para {rfc}?",
  timbrar: "Timbrar la nota",
  timbrando: "Timbrando…",
  notaHecha: "La nota de crédito quedó timbrada.",
  // Sustituir (en el formulario de factura).
  sustituyendo:
    "Sustituyes la factura {factura} · {total}. Al timbrar, se pide cancelar la anterior con el motivo 01.",
  soltarSustitucion: "No sustituir",
  avisoCancelacion:
    "La nueva quedó timbrada, pero la anterior no se pudo cancelar: {motivo}",
};
