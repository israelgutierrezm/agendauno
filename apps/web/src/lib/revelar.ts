import { onBeforeUnmount, onMounted, type Ref } from "vue";

/**
 * Aparición al entrar en pantalla de los `.reveal` que hay dentro de `raiz` (páginas
 * comerciales: portada, /clases y /citas) y seguimiento de progreso de scroll
 * (`--scroll-progreso` y `--scroll-y`). Sin JavaScript, o con `prefers-reduced-motion`,
 * el contenido se ve igual y sin animación: `.reveal` nunca oculta nada, `.reveal-in`
 * solo lo desliza al aparecer (`marketing/landing.css`). Todo ocurre en `onMounted`,
 * así que no estorba al prerender.
 */
export function useRevelar(raiz: Ref<HTMLElement | undefined>): void {
  let observador: IntersectionObserver | undefined;
  let rafId = 0;
  let onScroll: (() => void) | undefined;

  onMounted(() => {
    const el = raiz.value;
    const nodos = Array.from(
      el?.querySelectorAll<HTMLElement>(".reveal, [data-scroll]") ?? [],
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
      { threshold: 0.08, rootMargin: "0px 0px -2% 0px" },
    );
    nodos.forEach((nodo) => observador?.observe(nodo));

    if (el) {
      const actualizarScroll = (): void => {
        rafId = 0;
        const y = window.scrollY || 0;
        const vh = Math.max(1, window.innerHeight || 1);
        const altoTotal = Math.max(
          1,
          document.documentElement.scrollHeight - vh,
        );
        const progreso = Math.min(1, Math.max(0, y / altoTotal));
        el.style.setProperty("--scroll-progreso", progreso.toFixed(4));
      };
      onScroll = (): void => {
        if (!rafId) rafId = window.requestAnimationFrame(actualizarScroll);
      };
      window.addEventListener("scroll", onScroll, { passive: true });
      window.addEventListener("resize", onScroll, { passive: true });
      actualizarScroll();
    }
  });

  onBeforeUnmount(() => {
    observador?.disconnect();
    if (onScroll) {
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", onScroll);
    }
    if (rafId) window.cancelAnimationFrame(rafId);
  });
}
