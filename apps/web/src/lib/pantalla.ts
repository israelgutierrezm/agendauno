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

/**
 * Lleva a la vista el detalle que se abrió junto a una lista (p. ej. el de la clase en
 * Recepción), justo debajo de la barra superior, si no lo está ya: así no hay que bajar
 * a buscarlo. Si su parte de arriba ya se ve en el primer tramo de la pantalla, no se
 * mueve nada (abrir otra clase no hace saltar la página). Va directo, sin animación: el
 * detalle carga su contenido al abrirse y eso cortaría un desplazamiento suave a medias.
 */
export function llevarALaVista(elemento: HTMLElement | null): void {
  if (elemento === null || typeof window === "undefined") return;
  const barra =
    document.querySelector(".tu-barra-superior")?.getBoundingClientRect()
      .height ?? 0;
  const arriba = elemento.getBoundingClientRect().top;
  if (arriba >= barra && arriba <= barra + window.innerHeight * 0.2) return;
  window.scrollTo({ top: Math.max(0, window.scrollY + arriba - barra - 16) });
}

/**
 * Deja `elemento` al centro de su tira con desplazamiento a lo ancho (p. ej. el día
 * elegido en la tira de días de la agenda del teléfono). Es lo que haría
 * `scrollIntoView({ inline: "center", block: "nearest" })`, pero solo mueve la tira:
 * aquel también desplaza la página hasta la tira si ya se bajó a la lista (al girar el
 * teléfono, la página saltaría). Una tira oculta (sin ancho) no se toca: se centra
 * cuando aparece. `suave` anima el movimiento, salvo con «reducir movimiento».
 */
export function centrarEnTira(
  tira: HTMLElement | null,
  elemento: HTMLElement | null,
  suave = false,
): void {
  if (tira === null || elemento === null || tira.clientWidth === 0) return;
  const caja = tira.getBoundingClientRect();
  const pieza = elemento.getBoundingClientRect();
  const destino =
    tira.scrollLeft +
    pieza.left -
    (caja.left + tira.clientLeft) -
    (tira.clientWidth - pieza.width) / 2;
  const left = Math.round(
    Math.max(0, Math.min(destino, tira.scrollWidth - tira.clientWidth)),
  );
  const quieto =
    typeof window.matchMedia === "function" &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (typeof tira.scrollTo === "function") {
    tira.scrollTo({ left, behavior: suave && !quieto ? "smooth" : "auto" });
  } else {
    tira.scrollLeft = left;
  }
}
