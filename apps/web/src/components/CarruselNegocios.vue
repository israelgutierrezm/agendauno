<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
  watchEffect,
} from "vue";

/*
| Carrusel de negocios. Recibe la lista ya filtrada por el padre (los 9 giros de
| clases, los 8 de citas o todos) y funciona con cualquier largo: si la lista cambia,
| vuelve al primero; con un solo negocio no gira ni muestra controles.
*/

interface Negocio {
  clave: string;
  nombre: string;
  descripcion: string;
  alt: string;
  src: string;
  href?: string;
}
const props = defineProps<{ negocios: readonly Negocio[] }>();
// Lo de abajo (el botón al registro) recibe el negocio activo y si la persona lo
// eligió (clic, teclado o deslizar): el giro automático no cuenta como elección.
// Elegir detiene el giro, para que el negocio elegido (y el `?giro=` del botón) no
// cambie mientras la persona lee y baja al botón; «Reanudar» lo vuelve a mover.
defineSlots<{
  default?(props: { negocio: Negocio | undefined; elegido: boolean }): unknown;
}>();
const activo = ref(0);
const elegido = ref(false);
const pausado = ref(false);
const INTERVALO_GIRO_MS = 5000;
const visible = ref(false);
const reducido = ref(false);
const documentoVisible = ref(true);
const raiz = ref<HTMLElement>();
const negocio = computed(() => props.negocios[activo.value]);
const varios = computed(() => props.negocios.length > 1);
const girando = computed(
  () =>
    visible.value &&
    !pausado.value &&
    !reducido.value &&
    documentoVisible.value &&
    varios.value,
);
// Otra lista (otra modalidad, otro largo): de vuelta al primero, sin índices fuera.
watch(
  () => props.negocios.map((n) => n.clave).join("|"),
  () => {
    activo.value = 0;
    elegido.value = false;
  },
);
let observador: IntersectionObserver | undefined;
let preferencia: MediaQueryList | undefined;
let inicio: { x: number; y: number } | undefined;
let deslizado = false;

function posicion(indice: number): number {
  // Distancia circular al activo, de -mitad a +mitad (cualquier largo, par o impar).
  const total = props.negocios.length;
  const mitad = Math.floor(total / 2);
  return ((((indice - activo.value + mitad) % total) + total) % total) - mitad;
}
function ir(indice: number, manual = true): void {
  if (props.negocios.length === 0) return;
  activo.value = (indice + props.negocios.length) % props.negocios.length;
  elegido.value = manual;
  if (manual) pausado.value = true;
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
      <svg
        class="orbita-vectores"
        viewBox="0 0 1200 500"
        fill="none"
        aria-hidden="true"
      >
        <defs>
          <linearGradient
            id="carrusel-trazo-sup"
            gradientUnits="userSpaceOnUse"
            x1="40"
            y1="270"
            x2="780"
            y2="42"
          >
            <stop offset="0%" stop-color="#c084fc" stop-opacity="0.72" />
            <stop offset="32%" stop-color="#38bdf8" stop-opacity="0.9" />
            <stop offset="72%" stop-color="#818cf8" stop-opacity="0.78" />
            <stop offset="100%" stop-color="#f472b6" stop-opacity="0.22" />
          </linearGradient>
          <radialGradient
            id="carrusel-esfera-azul"
            cx="34%"
            cy="28%"
            r="68%"
            fx="28%"
            fy="24%"
          >
            <stop offset="0%" stop-color="#ffffff" />
            <stop offset="32%" stop-color="#bae6fd" />
            <stop offset="72%" stop-color="#38bdf8" />
            <stop offset="100%" stop-color="#0284c7" />
          </radialGradient>
        </defs>
        <g opacity="0.42">
          <path
            d="M 55 288 C -30 142, 210 10, 770 46"
            stroke="url(#carrusel-trazo-sup)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M 55 288 C -30 142, 210 10, 770 46"
            stroke="url(#carrusel-trazo-sup)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#carrusel-esfera-azul)" opacity="0.52">
          <animateMotion
            path="M 55 288 C -30 142, 210 10, 770 46"
            dur="10s"
            repeatCount="indefinite"
            keyPoints="0.12;0.58;0.12"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
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
    <div v-if="varios" class="orbita-controles">
      <button
        type="button"
        aria-label="Negocio anterior"
        @click="ir(activo - 1)"
      >
        <IconoNav nombre="atras" :tam="18" />
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
        <IconoNav nombre="flecha" :tam="18" />
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
      <template v-for="(item, i) in negocios" :key="item.clave">
        <a
          v-if="item.href"
          v-show="i === activo"
          :href="item.href"
          class="orbita-sector-enlace"
          @pointerenter="pausado = true"
          >Explorar {{ item.nombre }} <IconoNav nombre="flecha" :tam="16"
        /></a>
      </template>
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
    <slot :negocio="negocio" :elegido="elegido"></slot>
  </div>
</template>

<style scoped>
.orbita-sector-enlace {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-top: 14px;
  color: var(--texto);
  font-size: 0.9rem;
  text-decoration: underline;
  text-underline-offset: 5px;
}
.orbita-sector-enlace:hover {
  color: var(--enlace);
}
.orbita-sector-enlace:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 6px;
  border-radius: 3px;
}

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
.orbita-vectores {
  position: absolute;
  inset: -4% -3%;
  width: 106%;
  height: 108%;
  pointer-events: none;
  z-index: 0;
  overflow: visible;
}
.orbita-foto {
  position: absolute;
  left: 50%;
  top: 5%;
  width: clamp(16rem, 30vw, 23rem);
  height: 86%;
  padding: 0;
  overflow: hidden;
  border: 4px solid color-mix(in srgb, #ffffff 90%, var(--superficie));
  border-radius: 26px;
  background: #07172c;
  color: white;
  text-align: left;
  cursor: pointer;
  box-shadow: 0 22px 45px -16px rgb(13 59 110 / 32%);
  transform: translateX(calc(-50% + var(--pos) * 72%)) scale(var(--escala))
    rotateY(var(--giro));
  transition:
    transform 750ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 550ms ease,
    filter 550ms ease;
  filter: saturate(0.72) brightness(0.84);
}
.dark .orbita-foto {
  border-color: rgb(255 255 255 / 14%);
}
.orbita-foto.es-principal {
  filter: none;
  box-shadow: 0 28px 60px -18px rgb(13 59 110 / 42%);
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
  border: 1px solid color-mix(in srgb, #3a8fd4 26%, var(--borde));
  border-radius: 999px;
  background: var(--superficie);
  color: var(--texto);
  box-shadow: 0 8px 20px -12px rgb(13 59 110 / 16%);
  cursor: pointer;
  font-size: 1.1rem;
  transition:
    border-color 0.2s ease,
    color 0.2s ease,
    transform 0.2s ease;
}
.orbita-controles button:hover {
  border-color: #3a8fd4;
  color: var(--primario-fuerte);
  transform: translateY(-1px);
}
.orbita-controles [data-reproducir] {
  grid-column: 2;
  grid-row: 2;
  justify-self: center;
  width: 2rem;
  height: 2rem;
  border: 0;
  background: transparent;
  box-shadow: none;
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
  min-height: 9rem;
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
  border: 1px solid color-mix(in srgb, #3a8fd4 24%, var(--borde));
  border-radius: 999px;
  padding: 0.52rem 0.95rem;
  background: color-mix(in srgb, var(--superficie) 92%, transparent);
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 500;
  cursor: pointer;
  box-shadow: 0 4px 14px -8px rgb(13 59 110 / 12%);
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    color 0.2s ease,
    box-shadow 0.2s ease;
}
.orbita-categorias button:hover {
  border-color: color-mix(in srgb, #3a8fd4 48%, var(--borde));
  color: var(--primario);
}
.orbita-categorias button[aria-pressed="true"] {
  background: #0d3b6e;
  color: #ffffff;
  border-color: #0d3b6e;
  box-shadow: 0 8px 20px -10px rgb(13 59 110 / 42%);
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
