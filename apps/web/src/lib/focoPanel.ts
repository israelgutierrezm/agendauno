import { nextTick, onBeforeUnmount, ref, watch, type Ref } from "vue";

// Los paneles pueden abrir otros paneles: solo el superior captura el teclado.
const abiertos: symbol[] = [];
// La capa base de los diálogos; cada uno que se abre queda sobre el anterior.
const CAPA_BASE = 50;
let overflowPrevio = "";

/**
 * Devuelve la capa (z-index) del panel: el último en abrirse va encima aunque se
 * haya montado antes (p. ej. la confirmación, que vive en App.vue, sobre un modal).
 */
export function useFocoPanel(
  abierto: () => boolean,
  panel: Ref<HTMLElement | null>,
  cerrar: () => void,
): Ref<number> {
  const id = Symbol("panel");
  const capa = ref(CAPA_BASE);
  let origen: HTMLElement | null = null;

  function enfocar(): void {
    panel.value?.focus({ preventScroll: true });
  }
  function teclado(evento: KeyboardEvent): void {
    if (abiertos.at(-1) !== id) return;
    if (evento.key === "Escape") {
      evento.preventDefault();
      evento.stopImmediatePropagation();
      cerrar();
    } else if (evento.key === "Tab") {
      const elementos = Array.from(
        panel.value?.querySelectorAll<HTMLElement>(
          "a[href], button, input, select, textarea, [tabindex]",
        ) ?? [],
      ).filter(
        (e) =>
          e.tabIndex >= 0 &&
          !e.matches(":disabled") &&
          !e.closest("[hidden], [inert]") &&
          getComputedStyle(e).display !== "none" &&
          getComputedStyle(e).visibility !== "hidden" &&
          e.getClientRects().length > 0,
      );
      const primero = elementos[0];
      const ultimo = elementos.at(-1);
      const activo = document.activeElement;
      if (!primero || !ultimo) {
        evento.preventDefault();
        enfocar();
      } else if (
        evento.shiftKey &&
        (activo === primero ||
          activo === panel.value ||
          !panel.value?.contains(activo))
      ) {
        evento.preventDefault();
        ultimo.focus();
      } else if (
        !evento.shiftKey &&
        (activo === ultimo || !panel.value?.contains(activo))
      ) {
        evento.preventDefault();
        primero.focus();
      }
    }
  }
  function contenerFoco(evento: FocusEvent): void {
    if (
      abiertos.at(-1) === id &&
      panel.value &&
      !panel.value.contains(evento.target as Node)
    )
      enfocar();
  }
  function liberar(): void {
    const indice = abiertos.indexOf(id);
    if (indice === -1) return;
    const eraSuperior = abiertos.at(-1) === id;
    abiertos.splice(indice, 1);
    window.removeEventListener("keydown", teclado);
    document.removeEventListener("focusin", contenerFoco);
    if (abiertos.length === 0) document.body.style.overflow = overflowPrevio;
    if (eraSuperior && origen?.isConnected)
      origen.focus({ preventScroll: true });
    origen = null;
  }
  watch(
    abierto,
    async (valor) => {
      if (!valor) {
        liberar();
        return;
      }
      origen =
        document.activeElement instanceof HTMLElement
          ? document.activeElement
          : null;
      if (abiertos.length === 0) {
        overflowPrevio = document.body.style.overflow;
        document.body.style.overflow = "hidden";
      }
      abiertos.push(id);
      capa.value = CAPA_BASE + abiertos.length - 1;
      window.addEventListener("keydown", teclado);
      document.addEventListener("focusin", contenerFoco);
      await nextTick();
      if (abierto() && abiertos.at(-1) === id) enfocar();
    },
    { immediate: true, flush: "post" },
  );
  onBeforeUnmount(liberar);
  return capa;
}
