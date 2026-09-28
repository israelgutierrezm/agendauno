import { ref, watch, type Ref } from "vue";

export type VistaListado = "lista" | "cuadricula";

/**
 * Cómo ve la persona un listado (tabla o tarjetas). Se recuerda por listado en este
 * navegador; si no hay almacenamiento, vale la predeterminada.
 */
export function useVistaListado(
  clave: string,
  predeterminada: VistaListado = "lista",
): Ref<VistaListado> {
  const llave = `tu.vista.${clave}`;
  let inicial: VistaListado = predeterminada;
  try {
    const guardada = localStorage.getItem(llave);
    if (guardada === "lista" || guardada === "cuadricula") {
      inicial = guardada;
    }
  } catch {
    // Sin almacenamiento: queda la predeterminada.
  }
  const vista = ref<VistaListado>(inicial);
  watch(vista, (valor) => {
    try {
      localStorage.setItem(llave, valor);
    } catch {
      // Ignora.
    }
  });
  return vista;
}
