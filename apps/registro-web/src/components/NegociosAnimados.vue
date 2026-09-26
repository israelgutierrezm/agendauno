<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";

const props = defineProps<{
  negocios: readonly string[];
  prefijo: string;
  pausar: string;
  reanudar: string;
}>();

const raiz = ref<HTMLElement>();
const indice = ref(0);
const texto = ref(props.negocios[0] ?? "");
const pausado = ref(false);
const reducido = ref(false);
const visible = ref(true);
const documentoVisible = ref(true);
const montado = ref(false);
const animando = computed(
  () =>
    montado.value &&
    !pausado.value &&
    !reducido.value &&
    visible.value &&
    documentoVisible.value &&
    props.negocios.length > 1,
);
let fase: "espera" | "borrar" | "escribir" = "espera";
let temporizador: ReturnType<typeof setTimeout> | undefined;
let preferencia: MediaQueryList | undefined;
let observador: IntersectionObserver | undefined;

function detener(): void {
  clearTimeout(temporizador);
  temporizador = undefined;
}
function programar(
  demora = fase === "espera" ? 2400 : fase === "borrar" ? 38 : 75,
): void {
  detener();
  if (animando.value) temporizador = setTimeout(avanzar, demora);
}
function avanzar(): void {
  if (!animando.value) return;
  if (fase === "espera") fase = "borrar";
  if (fase === "borrar") {
    texto.value = Array.from(texto.value).slice(0, -1).join("");
    if (!texto.value) {
      indice.value = (indice.value + 1) % props.negocios.length;
      fase = "escribir";
      programar(300);
      return;
    }
  } else {
    const palabra = Array.from(props.negocios[indice.value] ?? "");
    texto.value = palabra.slice(0, Array.from(texto.value).length + 1).join("");
    if (texto.value === palabra.join("")) fase = "espera";
  }
  programar();
}
function reiniciar(): void {
  indice.value = 0;
  texto.value = props.negocios[0] ?? "";
  fase = "espera";
  programar();
}
function actualizarPreferencia(): void {
  reducido.value = preferencia?.matches ?? false;
  if (reducido.value) reiniciar();
}
function actualizarVisibilidad(): void {
  documentoVisible.value = document.visibilityState !== "hidden";
}
watch(animando, () => programar());
watch(() => props.negocios, reiniciar);
onMounted(() => {
  preferencia = window.matchMedia?.("(prefers-reduced-motion: reduce)");
  actualizarPreferencia();
  actualizarVisibilidad();
  preferencia?.addEventListener("change", actualizarPreferencia);
  document.addEventListener("visibilitychange", actualizarVisibilidad);
  if ("IntersectionObserver" in window && raiz.value) {
    observador = new IntersectionObserver(([entrada]) => {
      visible.value = entrada?.isIntersecting ?? false;
    });
    observador.observe(raiz.value);
  }
  montado.value = true;
});
onBeforeUnmount(() => {
  detener();
  observador?.disconnect();
  preferencia?.removeEventListener("change", actualizarPreferencia);
  document.removeEventListener("visibilitychange", actualizarVisibilidad);
});
</script>

<template>
  <div
    ref="raiz"
    class="negocios-animados"
    :class="{ 'esta-animando': animando }"
  >
    <!-- Texto completo y estable: el lector de pantalla no anuncia cada tecla. -->
    <p class="sr-only">{{ prefijo }} {{ negocios.join(", ") }}.</p>
    <div class="negocios-frase" aria-hidden="true">
      <span class="negocios-prefijo">{{ prefijo }}</span>
      <span class="negocios-escenario">
        <span
          v-for="negocio in negocios"
          :key="negocio"
          class="negocios-medida"
          >{{ negocio }}</span
        >
        <span class="negocios-texto"
          >{{ texto
          }}<span v-if="!reducido" class="negocios-cursor">|</span></span
        >
      </span>
    </div>
    <button
      v-if="negocios.length > 1 && !reducido"
      type="button"
      class="negocios-control"
      :aria-label="pausado ? reanudar : pausar"
      :title="pausado ? reanudar : pausar"
      @click="pausado = !pausado"
    >
      <svg
        viewBox="0 0 20 20"
        width="14"
        height="14"
        fill="currentColor"
        aria-hidden="true"
      >
        <path v-if="pausado" d="m6 3 10 7-10 7z" />
        <path v-else d="M5 4h3v12H5zm7 0h3v12h-3z" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.negocios-animados {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  margin-bottom: 1rem;
  max-width: 100%;
}
.negocios-frase {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  column-gap: 0.4em;
  min-width: 0;
  font-size: clamp(0.95rem, 1.3vw, 1.1rem);
  line-height: 1.6;
}
.negocios-prefijo {
  color: var(--texto-suave);
  white-space: nowrap;
}
.negocios-escenario {
  display: inline-grid;
  padding-right: 0.6em;
  color: var(--exito);
  font-weight: 500;
}
.negocios-medida,
.negocios-texto {
  grid-area: 1 / 1;
  white-space: nowrap;
}
.negocios-medida {
  visibility: hidden;
  pointer-events: none;
}
.negocios-cursor {
  display: inline-block;
  margin-left: 0.08em;
  font-weight: 300;
}
.esta-animando .negocios-cursor {
  animation: cursor-teclado 1s step-end infinite;
}
.negocios-control {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 32px;
  height: 36px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--texto-suave);
  cursor: pointer;
}
.negocios-control:hover {
  color: var(--texto);
  background: color-mix(in srgb, var(--texto) 6%, transparent);
}
.negocios-control:focus-visible {
  outline: 2px solid var(--exito);
  outline-offset: 2px;
}
@keyframes cursor-teclado {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0;
  }
}
@media (max-width: 1023px) {
  .negocios-animados {
    justify-content: center;
  }
}
@media (max-width: 480px) {
  .negocios-frase {
    flex-direction: column;
    align-items: center;
  }
}
@media (prefers-reduced-motion: reduce) {
  .negocios-cursor {
    animation: none !important;
    display: none;
  }
}
</style>
