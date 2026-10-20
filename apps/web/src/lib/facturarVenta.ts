import type { RouteLocationRaw } from "vue-router";

import { puedeEntrar } from "@/lib/acceso";
import type { useSesionTenantStore } from "@/stores/sesionTenant";

type Sesion = ReturnType<typeof useSesionTenantStore>;

/**
 * La factura de una venta (ADR 0179): desde un cobro o una venta del mostrador se va a
 * Facturas con la venta, y ahí el borrador llena receptor, conceptos y forma de pago.
 * Al timbrar queda ligada: la venta se factura una vez.
 */

/** ¿Factura desde las ventas? Ve Facturas, emite y el negocio puede facturar. */
export function puedeFacturarVentas(sesion: Sesion): boolean {
  return (
    puedeEntrar("facturas", sesion) &&
    sesion.puede("ordenes.gestionar") &&
    sesion.estudio?.factura_posible !== false &&
    sesion.estudio?.facturacion_disponible !== false
  );
}

/** Facturas con el borrador de esa venta: una orden o una venta del mostrador. */
export function rutaFacturarVenta(
  venta: { orden: string } | { venta: string },
): RouteLocationRaw {
  return { name: "facturas", query: venta };
}

/** Su factura en el listado, buscada por su folio fiscal. */
export function rutaFacturaDeVenta(uuid: string | null): RouteLocationRaw {
  return { name: "facturas", query: uuid ? { q: uuid } : {} };
}
