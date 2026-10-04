<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from "vue";

/**
 * Menú desplegable que flota sobre la página (se monta en `<body>`): no lo recortan
 * las tarjetas ni las tablas que ocultan lo que se sale de ellas. Se abre debajo de
 * su botón o, si no cabe, arriba. Se cierra con un clic fuera, con Escape o al
 * desplazar la página.
 */
const props = withDefaults(
  defineProps<{
    abierto: boolean;
    ancla: HTMLElement | null;
    ancho?: number;
  }>(),
  { ancho: 224 },
);
const emit = defineEmits<{ cerrar: [] }>();

const menu = ref<HTMLElement | null>(null);
const posicion = ref({ top: 0, left: 0 });
// Hasta medirlo no se muestra (evita que aparezca un instante en la esquina).
const colocado = ref(false);

const MARGEN = 4;
const BORDE = 8;

function colocar(): void {
  const ancla = props.ancla;
  const caja = menu.value;
  if (ancla === null || caja === null) return;
  const boton = ancla.getBoundingClientRect();
  const alto = caja.offsetHeight;
  const abajo = boton.bottom + MARGEN;
  const arriba = boton.top - MARGEN - alto;
  const noCabeAbajo = abajo + alto > window.innerHeight - BORDE;
  posicion.value = {
    top: noCabeAbajo && arriba >= BORDE ? arriba : abajo,
    left: Math.max(
      BORDE,
      Math.min(boton.left, window.innerWidth - props.ancho - BORDE),
    ),
  };
  colocado.value = true;
}

function alPresionarFuera(e: PointerEvent): void {
  const objetivo = e.target as Node | null;
  if (
    objetivo !== null &&
    (menu.value?.contains(objetivo) || props.ancla?.contains(objetivo))
  ) {
    return;
  }
  emit("cerrar");
}
function alTeclear(e: KeyboardEvent): void {
  if (e.key === "Escape") emit("cerrar");
}
function cerrar(): void {
  emit("cerrar");
}

function escuchar(): void {
  document.addEventListener("pointerdown", alPresionarFuera, true);
  document.addEventListener("keydown", alTeclear);
  window.addEventListener("scroll", cerrar, true);
  window.addEventListener("resize", cerrar);
}
function dejarDeEscuchar(): void {
  document.removeEventListener("pointerdown", alPresionarFuera, true);
  document.removeEventListener("keydown", alTeclear);
  window.removeEventListener("scroll", cerrar, true);
  window.removeEventListener("resize", cerrar);
}

watch(
  () => props.abierto,
  async (abierto) => {
    dejarDeEscuchar();
    colocado.value = false;
    if (!abierto) return;
    await nextTick();
    colocar();
    escuchar();
  },
  { immediate: true },
);
onBeforeUnmount(dejarDeEscuchar);
</script>

<template>
  <Teleport to="body">
    <div
      v-if="abierto"
      ref="menu"
      role="menu"
      class="tu-menu-flotante"
      :style="{
        top: `${posicion.top}px`,
        left: `${posicion.left}px`,
        width: `${ancho}px`,
        visibility: colocado ? 'visible' : 'hidden',
      }"
    >
      <slot />
    </div>
  </Teleport>
</template>

<style scoped>
.tu-menu-flotante {
  position: fixed;
  z-index: 60;
  overflow: hidden;
  border: 1px solid var(--borde);
  border-radius: 0.5rem;
  background: var(--superficie);
  box-shadow: 0 10px 30px rgb(15 23 42 / 14%);
}
</style>
