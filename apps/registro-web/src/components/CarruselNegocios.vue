<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watchEffect,
} from "vue";

interface Negocio {
  clave: string;
  nombre: string;
  descripcion: string;
  alt: string;
  src: string;
}
const props = defineProps<{ negocios: readonly Negocio[] }>();
const activo = ref(0);
const pausado = ref(false);
const ciclo = ref(0);
const INTERVALO_GIRO_MS = 5000;
const visible = ref(false);
const reducido = ref(false);
const documentoVisible = ref(true);
const raiz = ref<HTMLElement>();
const negocio = computed(() => props.negocios[activo.value]);
const girando = computed(
  () =>
    visible.value &&
    !pausado.value &&
    !reducido.value &&
    documentoVisible.value &&
    props.negocios.length > 1,
);
let observador: IntersectionObserver | undefined;
let preferencia: MediaQueryList | undefined;
let inicio: { x: number; y: number } | undefined;
let deslizado = false;

function posicion(indice: number): number {
  const total = props.negocios.length;
  return (
    ((indice - activo.value + total + Math.floor(total / 2)) % total) -
    Math.floor(total / 2)
  );
}
function ir(indice: number, manual = true): void {
  if (props.negocios.length === 0) return;
  activo.value = (indice + props.negocios.length) % props.negocios.length;
  if (manual) ciclo.value += 1;
}
function seleccionar(indice: number): void {
  if (deslizado) {
    deslizado = false;
    return;
  }
  ir(indice);
}
function teclado(event: KeyboardEvent): void {
  if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
  pausado.value = true;
  event.preventDefault();
  if (event.key === "Home") ir(0);
  else if (event.key === "End") ir(props.negocios.length - 1);
  else ir(activo.value + (event.key === "ArrowRight" ? 1 : -1));
  if ((event.target as HTMLElement).closest(".orbita-foto")) {
    void nextTick(() =>
      raiz.value
        ?.querySelector<HTMLButtonElement>(".es-principal")
        ?.focus({ preventScroll: true }),
    );
  }
}
function enfocar(event: FocusEvent): void {
  const destino = event.target as HTMLElement;
  if (
    destino.matches(":focus-visible") &&
    !destino.closest("[data-reproducir]")
  )
    pausado.value = true;
}
function empezarGesto(event: PointerEvent): void {
  inicio = { x: event.clientX, y: event.clientY };
  deslizado = false;
}
function terminarGesto(event: PointerEvent): void {
  if (!inicio) return;
  const dx = event.clientX - inicio.x;
  const dy = event.clientY - inicio.y;
  if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) {
    ir(activo.value + (dx < 0 ? 1 : -1));
    deslizado = true;
  }
  inicio = undefined;
}
function actualizarMovimiento(): void {
  reducido.value = preferencia?.matches ?? false;
}
function actualizarVisibilidad(): void {
  documentoVisible.value = !document.hidden;
}
watchEffect((limpiar) => {
  // Una selección manual reinicia la espera sin desactivar el giro automático.
  void ciclo.value;
  if (!girando.value) return;
  const intervalo = window.setInterval(
    () => ir(activo.value + 1, false),
    INTERVALO_GIRO_MS,
  );
  limpiar(() => window.clearInterval(intervalo));
});
onMounted(() => {
  preferencia = window.matchMedia?.("(prefers-reduced-motion: reduce)");
  actualizarMovimiento();
  preferencia?.addEventListener("change", actualizarMovimiento);
  actualizarVisibilidad();
  document.addEventListener("visibilitychange", actualizarVisibilidad);
  if ("IntersectionObserver" in window) {
    observador = new IntersectionObserver(
      ([entrada]) => {
        visible.value = entrada?.isIntersecting ?? false;
      },
      { threshold: 0.3 },
    );
    if (raiz.value) observador.observe(raiz.value);
  } else visible.value = true;
});
onBeforeUnmount(() => {
  observador?.disconnect();
  preferencia?.removeEventListener("change", actualizarMovimiento);
  document.removeEventListener("visibilitychange", actualizarVisibilidad);
});
</script>

<template>
  <div
    ref="raiz"
    class="orbita"
    role="region"
    aria-roledescription="carrusel"
    aria-label="Encuentra tu tipo de negocio"
    @focusin="enfocar"
    @keydown="teclado"
  >
    <div
      class="orbita-escena"
      @pointerdown="empezarGesto"
      @pointerup="terminarGesto"
      @pointercancel="inicio = undefined"
    >
      <div class="orbita-halo" aria-hidden="true"></div>
      <div class="orbita-anillo" aria-hidden="true"></div>
      <button
        v-for="(item, i) in negocios"
        :key="item.clave"
        type="button"
        class="orbita-foto"
        :class="{
          'es-principal': i === activo,
          fuera: Math.abs(posicion(i)) > 2,
        }"
        :style="{
          '--pos': posicion(i),
          '--escala': 1 - Math.min(Math.abs(posicion(i)), 3) * 0.15,
          '--giro': posicion(i) * -12 + 'deg',
          zIndex: 10 - Math.abs(posicion(i)),
        }"
        :tabindex="i === activo ? 0 : -1"
        :aria-hidden="Math.abs(posicion(i)) > 2"
        :aria-label="'Ver ' + item.nombre"
        :aria-pressed="i === activo"
        @click="seleccionar(i)"
      >
        <img
          :src="item.src"
          :alt="item.alt"
          width="1122"
          height="1402"
          loading="lazy"
          decoding="async"
          draggable="false"
        />
        <span class="orbita-sombra" aria-hidden="true"></span>
        <span class="orbita-copy">
          <span class="orbita-etiqueta">Tu pasión. Tu negocio.</span>
          <span class="orbita-nombre">{{ item.nombre }}</span>
        </span>
      </button>
    </div>
    <div class="orbita-controles">
      <button
        type="button"
        aria-label="Negocio anterior"
        @click="ir(activo - 1)"
      >
        ←
      </button>
      <span class="orbita-paginacion"
        ><strong>{{ String(activo + 1).padStart(2, "0") }}</strong> /
        {{ String(negocios.length).padStart(2, "0") }}</span
      >
      <button
        type="button"
        aria-label="Negocio siguiente"
        @click="ir(activo + 1)"
      >
        →
      </button>
      <button
        v-if="!reducido"
        type="button"
        data-reproducir
        :aria-label="pausado ? 'Reanudar carrusel' : 'Pausar carrusel'"
        @click="pausado = !pausado"
      >
        <svg
          viewBox="0 0 24 24"
          width="16"
          height="16"
          fill="currentColor"
          aria-hidden="true"
        >
          <path v-if="pausado" d="M8 5v14l11-7z" />
          <path v-else d="M6 5h4v14H6zm8 0h4v14h-4z" />
        </svg>
      </button>
    </div>
    <div
      class="orbita-detalle"
      :aria-live="girando ? 'off' : 'polite'"
      aria-atomic="true"
    >
      <h3>{{ negocio?.nombre }}</h3>
      <p>{{ negocio?.descripcion }}</p>
    </div>
    <div class="orbita-categorias" aria-label="Seleccionar tipo de negocio">
      <button
        v-for="(item, i) in negocios"
        :key="item.clave"
        type="button"
        :aria-pressed="activo === i"
        @click="ir(i)"
      >
        {{ item.nombre }}
      </button>
    </div>
    <slot :negocio="negocio"></slot>
  </div>
</template>

<style scoped>
.orbita {
  position: relative;
  margin-top: 1.5rem;
  text-align: center;
}
.orbita-escena {
  height: clamp(25rem, 43vw, 33rem);
  position: relative;
  perspective: 1200px;
  isolation: isolate;
  touch-action: pan-y;
}
.orbita-halo {
  position: absolute;
  inset: 3% 8% 0;
  border-radius: 50%;
  background: radial-gradient(
    ellipse,
    color-mix(in srgb, var(--primario) 15%, transparent),
    transparent 67%
  );
}
.orbita-anillo {
  position: absolute;
  left: 5%;
  right: 5%;
  bottom: 3%;
  height: 35%;
  border: 1px solid color-mix(in srgb, var(--primario) 16%, transparent);
  border-radius: 50%;
  transform: rotate(-6deg);
}
.orbita-foto {
  position: absolute;
  left: 50%;
  top: 5%;
  width: clamp(16rem, 30vw, 23rem);
  height: 86%;
  padding: 0;
  overflow: hidden;
  border: 3px solid var(--superficie);
  border-radius: var(--radio-imagen, 22px);
  background: #07172c;
  color: white;
  text-align: left;
  cursor: pointer;
  box-shadow: 0 22px 45px -16px rgb(3 27 78 / 35%);
  transform: translateX(calc(-50% + var(--pos) * 72%)) scale(var(--escala))
    rotateY(var(--giro));
  transition:
    transform 850ms cubic-bezier(0.22, 0.8, 0.25, 1),
    opacity 650ms ease,
    filter 650ms ease;
  filter: saturate(0.65) brightness(0.8);
}
.orbita-foto.es-principal {
  filter: none;
  box-shadow: 0 26px 60px -18px rgb(3 27 78 / 45%);
}
.orbita-foto.fuera {
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
}
.orbita-foto img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
  user-select: none;
}
.orbita-sombra {
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, transparent 45%, rgb(3 15 35 / 85%));
}
.orbita-copy {
  position: absolute;
  left: 1.5rem;
  right: 1.5rem;
  bottom: 1.7rem;
  z-index: 1;
  display: grid;
  gap: 0.8rem;
}
.orbita-etiqueta {
  font-size: 0.65rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  opacity: 0.85;
}
.orbita-nombre {
  font-size: clamp(1.4rem, 2.45vw, 2.05rem);
  font-weight: 300;
  line-height: 1.05;
  letter-spacing: -0.04em;
}
.orbita-controles {
  display: grid;
  grid-template-columns: 2.75rem 4.5rem 2.75rem;
  justify-content: center;
  align-items: center;
  gap: 0.45rem 0.75rem;
  margin-top: 0.25rem;
}
.orbita-controles button {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton, 11px);
  background: var(--superficie);
  color: var(--texto);
  cursor: pointer;
  font-size: 1.1rem;
}
.orbita-controles button:hover {
  border-color: var(--primario);
  color: var(--primario-fuerte);
}
.orbita-controles [data-reproducir] {
  grid-column: 2;
  grid-row: 2;
  justify-self: center;
  width: 2rem;
  height: 2rem;
  border: 0;
  background: transparent;
}
.orbita-paginacion {
  font-size: 0.8rem;
  color: var(--texto-suave);
  font-variant-numeric: tabular-nums;
}
.orbita-paginacion strong {
  color: var(--texto);
  margin-right: 0.4rem;
}
.orbita-detalle {
  min-height: 6rem;
  margin: 1.3rem auto 0;
  max-width: 35rem;
  padding-inline: 1rem;
}
.orbita-detalle h3 {
  font-size: 1.2rem;
  font-weight: 300;
}
.orbita-detalle p {
  margin-top: 0.45rem;
  color: var(--texto-suave);
  font-size: 0.94rem;
  line-height: 1.55;
}
.orbita-categorias {
  display: flex;
  gap: 0.5rem;
  justify-content: center;
  flex-wrap: wrap;
  max-width: 54rem;
  margin: 0.25rem auto 1.75rem;
}
.orbita-categorias button {
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton, 11px);
  padding: 0.55rem 0.85rem;
  background: transparent;
  color: var(--texto-suave);
  font-size: 0.78rem;
  cursor: pointer;
}
.orbita-categorias button[aria-pressed="true"] {
  background: var(--texto);
  color: var(--fondo);
  border-color: var(--texto);
}
button:focus-visible {
  outline: 3px solid var(--primario);
  outline-offset: 4px;
}
@media (max-width: 639px) {
  .orbita-escena {
    height: 25rem;
    margin-inline: -1rem;
  }
  .orbita-foto {
    width: 65%;
    max-width: 20rem;
    border-radius: 1.3rem;
    transform: translateX(calc(-50% + var(--pos) * 66%)) scale(var(--escala))
      rotateY(var(--giro));
  }
  .orbita-nombre {
    font-size: 1.53rem;
    left: 1rem;
    right: 1rem;
  }
  .orbita-etiqueta {
    left: 1rem;
    font-size: 0.58rem;
    bottom: 5.4rem;
  }
  .orbita-categorias {
    gap: 0.4rem;
  }
  .orbita-categorias button {
    padding: 0.5rem 0.65rem;
    font-size: 0.72rem;
  }
}
@media (prefers-reduced-motion: reduce) {
  .orbita-foto {
    transition: none;
  }
}
</style>
