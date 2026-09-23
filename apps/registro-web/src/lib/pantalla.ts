import { onBeforeUnmount, onMounted, ref, type Ref } from "vue";

/**
 * ¿La ventana mide al menos `px` de ancho? Se actualiza al cambiar el tamaño. Sirve
 * para mostrar el detalle junto a la lista en escritorio y como panel en móvil.
 */
export function useAnchoMinimo(px: number): Ref<boolean> {
  const consulta =
    typeof window !== "undefined" && typeof window.matchMedia === "function"
      ? window.matchMedia(`(min-width: ${px}px)`)
      : null;
  const cumple = ref(consulta?.matches ?? false);
  const alCambiar = (e: MediaQueryListEvent): void => {
    cumple.value = e.matches;
  };
  onMounted(() => consulta?.addEventListener("change", alCambiar));
  onBeforeUnmount(() => consulta?.removeEventListener("change", alCambiar));
  return cumple;
}
