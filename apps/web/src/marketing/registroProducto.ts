import { computed, type ComputedRef } from "vue";

import { PRODUCTOS, type Producto } from "../lib/producto.ts";
import { usePreciosPublicos } from "./preciosPublicos.ts";
import { PRODUCTO_COMERCIAL } from "./seoConfig.ts";

/**
 * ¿El producto de esta página recibe registros de negocios? (ADR 0108). TurnoUno abre
 * hasta su lanzamiento: mientras, sus botones de «Probar gratis» llevan a dejar los
 * datos («Quiero que me avisen») y el registro muestra la lista de interesados. Lo
 * decide el superadmin; el HTML pre-generado usa el respaldo de `precios.ts`.
 */
export function useRegistroDelProducto(
  producto: Producto = PRODUCTO_COMERCIAL,
): {
  abierto: ComputedRef<boolean>;
  diasPrueba: ComputedRef<number>;
  nombre: string;
} {
  const precios = usePreciosPublicos();
  return {
    abierto: computed(() => precios.datos.registro[producto]),
    diasPrueba: computed(
      () => precios.datos[PRODUCTOS[producto].modalidad].dias_prueba,
    ),
    nombre: PRODUCTOS[producto].nombre,
  };
}
