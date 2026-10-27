import { computed, type ComputedRef } from "vue";

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
 * Se cobra en la sucursal de la barra (la fija): su caja lo registra (ADR 0175). Con
 * varias sucursales y «Todas» en la barra no se sabe en qué caja entra: `sinSucursal`
 * lo dice para pedir elegirla antes de cobrar (Ventas y la venta rápida).
 */
export function crearVenta(): {
  vender: (base: string, orden: OrdenNueva, metodo: string) => Promise<string>;
  sinSucursal: ComputedRef<boolean>;
} {
  let intento: { venta: string; clave: string } | null = null;
  const sucursales = useSucursales();
  const sinSucursal = computed(
    () => sucursales.varias.value && sucursales.fija.value === null,
  );

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

  return { vender, sinSucursal };
}

/** Un derecho del alumno como lo da `GET /miembros/{id}/derechos` (unidades: 1000 = 1). */
export interface DerechoDeVenta {
  id: string;
  ilimitado: boolean;
  saldo: number | null;
  disponible: number | null;
}

/**
 * El derecho que dejó una venta, comparando lo que tenía antes con lo de después: el
 * nuevo o, si la venta se sumó a uno que ya tenía (clases extra), el que cambió. Sin
 * ninguno, null (el aviso no inventa un saldo).
 */
export function derechoVendido(
  antes: DerechoDeVenta[],
  despues: DerechoDeVenta[],
): DerechoDeVenta | null {
  const previos = new Map(antes.map((d) => [d.id, d]));
  return (
    despues.find((d) => !previos.has(d.id)) ??
    despues.find((d) => {
      const previo = previos.get(d.id);
      return (
        previo !== undefined && (previo.disponible ?? 0) !== (d.disponible ?? 0)
      );
    }) ??
    null
  );
}
