import { onBeforeUnmount, onMounted, type Ref } from "vue";

/**
 * Aparición al entrar en pantalla de los `.reveal` que hay dentro de `raiz` (páginas
 * comerciales: portada, /clases y /citas). Sin JavaScript, o con
 * `prefers-reduced-motion`, el contenido se ve igual y sin animación: `.reveal` nunca
 * oculta nada, `.reveal-in` solo lo desliza al aparecer (`marketing/landing.css`).
 * Todo ocurre en `onMounted`, así que no estorba al prerender.
 */
export function useRevelar(raiz: Ref<HTMLElement | undefined>): void {
  let observador: IntersectionObserver | undefined;

  onMounted(() => {
    const nodos = Array.from(
      raiz.value?.querySelectorAll<HTMLElement>(".reveal") ?? [],
    );
    const reducido =
      window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;

    if (reducido || !("IntersectionObserver" in window)) {
      nodos.forEach((nodo) => nodo.classList.add("reveal-in"));
      return;
    }

    observador = new IntersectionObserver(
      (entradas) => {
        for (const entrada of entradas) {
          if (entrada.isIntersecting) {
            entrada.target.classList.add("reveal-in");
            observador?.unobserve(entrada.target);
          }
        }
      },
      { threshold: 0.18 },
    );
    nodos.forEach((nodo) => observador?.observe(nodo));
  });

  onBeforeUnmount(() => observador?.disconnect());
}
