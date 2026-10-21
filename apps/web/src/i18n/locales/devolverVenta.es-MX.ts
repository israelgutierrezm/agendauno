// Devolver algunos productos de una venta del mostrador (components/DevolverVenta.vue;
// ADR 0178).
export default {
  accion: "Devolver",
  ayuda:
    "Elige cuántas piezas regresan. El dinero sale de la caja abierta y las piezas vuelven al inventario. La propina no se devuelve aquí.",
  quedan: "Quedan {n} por devolver · {precio} c/u",
  piezasDe: "Piezas de {articulo} que regresan",
  salePor: "Se devuelve por",
  // Venta con varias formas (ADR 0178): si por la elegida no alcanza.
  salePorAyuda:
    "Primero por esta forma; si por ella no alcanza, lo demás sale por las otras formas de la venta.",
  motivo: "Motivo",
  motivoPh: "Venía abierta, no le quedó…",
  devolver: "Devolver",
  devolverMonto: "Devolver {monto}",
  confirmar:
    "¿Devolver {monto}? Sale de la caja abierta y las piezas vuelven al inventario.",
  ok: "Se devolvieron {monto}.",
  // Salió por más de una forma: cuánto por cada una.
  okPartes: "Se devolvieron {monto} ({partes}).",
  devuelto: "Devuelto {monto}",
};
