// Cobro al terminar una cita (components/CobroCita.vue): servicio, productos y propina.
export default {
  titulo: "Cobrar a {cliente}",
  servicio: "Servicio",
  servicioPagado:
    "El servicio ya está cobrado; puedes agregar productos o propina.",
  productos: "Productos",
  agregarProducto: "Se lleva algún producto",
  elegirProducto: "Elige un producto",
  sinProductos: "No hay productos con existencias",
  errorProductos: "No se pudieron cargar los productos.",
  agregar: "Agregar",
  quitar: "Quitar",
  cantidad: "Cantidad de {nombre}",
  propina: "Propina",
  sinPropina: "Sin propina",
  otra: "Otra",
  metodo: "Forma de pago",
  recibe: "Recibe",
  falta: "Faltan {monto} para cobrar.",
  suCambio: "Su cambio: {monto}",
  enviarTicket: "Mandarle su ticket a {correo}",
  cobrar: "Cobrar {monto}",
  cobrando: "Cobrando…",
  hecho: "Cobrado.",
  total: "Total",
  recibido: "Recibido",
  cambio: "Cambio",
  folio: "Ticket {folio}",
  // El stock de la sucursal de la cita (R07).
  enEstaSucursal: "{n} aquí",
  soloHay: "Solo hay {n} aquí",
  // El envío del ticket, aparte del cobro (R06).
  ticketEnviado: "Le mandamos su ticket por correo.",
  ticketPendiente: "Su ticket va en camino a su correo.",
  mandarTicket: "Mandarle su ticket a {correo}",
  ticketFallido:
    "El cobro quedó registrado, pero no se pudo mandar el ticket por correo.",
  reenviarTicket: "Reenviar ticket",
  listo: "Listo",
  // Un monto que no se pudo leer (p. ej. con la coma o el punto de otro país).
  montoIlegible: "No entendimos este monto: escríbelo solo con números.",
};
