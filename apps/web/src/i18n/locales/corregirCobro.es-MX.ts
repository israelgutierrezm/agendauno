// Corregir un cobro en caja (components/CorregirCobro.vue; ADR 0086 y 0087).
export default {
  corregirMetodo: "Corregir forma de pago",
  corregirMetodoAyuda:
    "Si se registró con la forma equivocada. El monto no cambia y queda en la bitácora.",
  guardarMetodo: "Guardar forma de pago",
  confirmarMetodo:
    "¿Cambiar la forma de pago de {antes} a {ahora}? El monto no cambia y queda en la bitácora.",
  okMetodo: "Forma de pago corregida.",
  anular: "Anular cobro",
  anularAyuda:
    "Para un cobro registrado por error: el dinero no entró. La venta vuelve a quedar por cobrar y, si dio créditos o una membresía sin usar, se retiran. Queda en la bitácora.",
  motivo: "Motivo",
  motivoPh: "Ej. Se registró dos veces, aún no paga",
  confirmarAnular:
    "¿Anular este cobro? Se marca como anulado, la venta vuelve a quedar por cobrar y se retira lo que dio. No se puede deshacer.",
  okAnulado: "Cobro anulado; la venta quedó por cobrar.",
  // Una venta de mostrador registrada por error (ADR 0089).
  venta: {
    anular: "Anular venta",
    anularAyuda:
      "Para una venta registrada por error: no cuenta en el corte y lo vendido regresa al inventario. Queda en la bitácora.",
    confirmarAnular:
      "¿Anular esta venta? No cuenta en el corte y lo vendido regresa al inventario. No se puede deshacer.",
    okAnulado: "Venta anulada; lo vendido regresó al inventario.",
  },
  corregir: "Corregir",
  anulada: "Anulada",
};
