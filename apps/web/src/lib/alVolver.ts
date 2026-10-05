import { onMounted, onUnmounted } from "vue";

/**
 * Vuelve a pedir los datos cuando la persona regresa a la pestaña o a la app (tras
 * atender a alguien, el teléfono en la bolsa…): lo que ve no se queda viejo. Espera
 * un mínimo entre recargas para no repetirlas al cambiar de ventana seguido.
 */
export function useRecargarAlVolver(
  recargar: () => unknown,
  minimoMs = 30_000,
): void {
  let ultima = Date.now();
  const alVolver = (): void => {
    if (document.visibilityState !== "visible") {
      return;
    }
    if (Date.now() - ultima < minimoMs) {
      return;
    }
    ultima = Date.now();
    void recargar();
  };
  onMounted(() => {
    document.addEventListener("visibilitychange", alVolver);
    window.addEventListener("focus", alVolver);
  });
  onUnmounted(() => {
    document.removeEventListener("visibilitychange", alVolver);
    window.removeEventListener("focus", alVolver);
  });
}
