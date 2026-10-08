import { api } from "@/lib/api";

export interface OrdenNueva {
  comprador_id: string;
  items: { producto_id: string; cantidad: number }[];
  codigo_promo?: string;
}

/**
 * Vender cobra en dos pasos: crear la orden y liquidarla. Si el cobro falla (p. ej.
 * sin red), la orden queda pendiente; al reintentar la MISMA venta se cobra esa orden
 * y no se crea otra (no quedan órdenes repetidas en «Por cobrar»).
 */
export function crearVenta(): {
  vender: (base: string, orden: OrdenNueva, metodo: string) => Promise<string>;
} {
  let sinCobrar: { clave: string; id: string } | null = null;

  async function vender(
    base: string,
    orden: OrdenNueva,
    metodo: string,
  ): Promise<string> {
    const clave = JSON.stringify([base, orden]);
    let id = sinCobrar?.clave === clave ? sinCobrar.id : null;
    if (id === null) {
      const { data } = await api.post<{ data: { id: string } }>(
        `${base}/ordenes`,
        orden,
      );
      id = data.data.id;
      sinCobrar = { clave, id };
    }
    await api.post(`${base}/ordenes/${id}/liquidar`, { metodo });
    sinCobrar = null;
    return id;
  }

  return { vender };
}
