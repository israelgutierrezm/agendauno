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
      el?.querySelectorAll<HTMLElement>(".reveal") ?? [],
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
      { threshold: 0.14, rootMargin: "0px 0px -4% 0px" },
    );
    nodos.forEach((nodo) => observador?.observe(nodo));

    if (el) {
      const animados = Array.from(
        el.querySelectorAll<HTMLElement>("[data-scroll]"),
      );
      const actualizarScroll = (): void => {
        rafId = 0;
        const y = window.scrollY || 0;
        const vh = Math.max(1, window.innerHeight || 1);
        const altoTotal = Math.max(
          1,
          document.documentElement.scrollHeight - vh,
        );
        const progreso = Math.min(1, Math.max(0, y / altoTotal));
        const heroScroll = Math.min(1, Math.max(0, y / (vh * 0.85)));
        el.style.setProperty("--scroll-y", `${Math.min(y, 900)}px`);
        el.style.setProperty("--scroll-progreso", progreso.toFixed(4));
        el.style.setProperty("--hero-scroll", heroScroll.toFixed(3));

        for (const item of animados) {
          const rect = item.getBoundingClientRect();
          if (rect.bottom < -180 || rect.top > vh + 180) continue;
          const entrada = Math.min(
            1,
            Math.max(0, (vh * 0.96 - rect.top) / (vh * 0.42)),
          );
          const salida = Math.min(
            1,
            Math.max(0, (rect.bottom - vh * 0.06) / (vh * 0.36)),
          );
          const foco = Math.min(entrada, salida);
          const progresoVista = Math.min(
            1,
            Math.max(0, (vh - rect.top) / Math.max(1, vh + rect.height)),
          );
          item.style.setProperty("--entrada-vista", entrada.toFixed(3));
          item.style.setProperty("--foco-vista", foco.toFixed(3));
          item.style.setProperty("--progreso-vista", progresoVista.toFixed(3));
        }
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
