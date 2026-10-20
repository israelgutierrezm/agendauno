import { api } from "@/lib/api";
import { useSucursales } from "@/lib/sucursalOperativa";

export interface OrdenNueva {
  comprador_id: string;
  items: { producto_id: string; cantidad: number }[];
  codigo_promo?: string;
}

/**
 * Vender cobra de una vez: el API crea la orden y la cobra en caja en una sola
 * transacción. Si el cobro no pasa (p. ej. la caja está cerrada), no queda ninguna
 * orden por cobrar. Cada intento lleva su clave: repetir la MISMA venta (sin red, doble
 * clic) devuelve la venta que ya se hizo, no cobra otra.
 *
 * Se cobra en la sucursal de la barra (la fija): su caja lo registra (ADR 0175).
 */
export function crearVenta(): {
  vender: (base: string, orden: OrdenNueva, metodo: string) => Promise<string>;
} {
  let intento: { venta: string; clave: string } | null = null;
  const sucursales = useSucursales();

  async function vender(
    base: string,
    orden: OrdenNueva,
    metodo: string,
  ): Promise<string> {
    const venta = JSON.stringify([base, orden, metodo]);
    if (intento?.venta !== venta) {
      intento = { venta, clave: crypto.randomUUID().replace(/-/g, "") };
    }
    const sede = sucursales.fija.value;
    const { data } = await api.post<{ data: { id: string } }>(
      `${base}/ordenes`,
      {
        ...orden,
        liquidar: {
          metodo,
          clave: intento.clave,
          ...(sede !== null ? { sucursal_id: sede } : {}),
        },
      },
    );
    intento = null;
    return data.data.id;
  }

  return { vender };
}
