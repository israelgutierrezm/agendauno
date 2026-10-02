<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from "vue";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import IconoNav from "@/components/IconoNav.vue";

/**
 * Foto de una persona (p. ej. el profesional al agendar) pulsable para verla en
 * grande, por si no recuerdan su nombre. La lupa aparece al pasar el cursor o al
 * enfocarla; en pantallas táctiles se ve siempre. Sin foto, solo la inicial.
 */
const props = withDefaults(
  defineProps<{
    nombre: string;
    foto?: string | null;
    tam?: "md" | "lg" | "xl";
  }>(),
  { foto: null, tam: "lg" },
);

const abierta = ref(false);
const cerrarBtn = ref<HTMLButtonElement | null>(null);
let antes: HTMLElement | null = null;

function alTeclear(e: KeyboardEvent): void {
  if (e.key === "Escape") {
    cerrar();
  } else if (e.key === "Tab") {
    // El diálogo solo tiene un control: el foco no debe salir al formulario.
    e.preventDefault();
    cerrarBtn.value?.focus();
  }
}

async function abrir(): Promise<void> {
  antes =
    document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
  abierta.value = true;
  window.addEventListener("keydown", alTeclear);
  await nextTick();
  cerrarBtn.value?.focus();
}

function cerrar(): void {
  abierta.value = false;
  window.removeEventListener("keydown", alTeclear);
  antes?.focus();
}

onBeforeUnmount(() => window.removeEventListener("keydown", alTeclear));
</script>

<template>
  <span class="fa-foto" :class="`fa-foto--${props.tam}`">
    <button
      v-if="foto"
      type="button"
      class="fa-abrir"
      data-prueba="ampliar-foto"
      :aria-label="$t('fotoAmpliable.ver', { nombre })"
      aria-haspopup="dialog"
      @click.stop.prevent="abrir"
    >
      <AvatarIniciales
        class="fa-avatar"
        :nombre="nombre"
        :foto="foto"
        :tam="tam"
      />
      <span class="fa-lupa" aria-hidden="true">
        <IconoNav nombre="ampliar" :tam="14" />
      </span>
    </button>
    <AvatarIniciales v-else class="fa-avatar" :nombre="nombre" :tam="tam" />
  </span>

  <Teleport to="body">
    <div
      v-if="abierta && foto"
      class="fa-velo"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('fotoAmpliable.titulo', { nombre })"
      data-prueba="foto-grande"
      @click.self="cerrar"
    >
      <figure class="fa-figura">
        <img :src="foto" :alt="nombre" class="fa-imagen" />
        <figcaption class="fa-nombre">{{ nombre }}</figcaption>
        <button
          ref="cerrarBtn"
          type="button"
          class="fa-cerrar"
          :aria-label="$t('fotoAmpliable.cerrar')"
          @click="cerrar"
        >
          <IconoNav nombre="cerrar" :tam="18" />
        </button>
      </figure>
    </div>
  </Teleport>
</template>

<style scoped>
.fa-foto {
  position: relative;
  display: inline-flex;
  flex-shrink: 0;
}
.fa-abrir {
  position: relative;
  display: inline-flex;
  padding: 0;
  border: 0;
  border-radius: 999px;
  background: transparent;
  cursor: zoom-in;
}
.fa-abrir:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 3px;
}
.fa-lupa {
  position: absolute;
  right: -0.25rem;
  bottom: -0.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: 999px;
  border: 1px solid var(--borde);
  background: var(--superficie);
  color: var(--texto);
  box-shadow: 0 1px 3px rgb(0 0 0 / 0.15);
  opacity: 0;
  transition: opacity 0.15s ease;
}
.fa-foto--md .fa-lupa {
  width: 1.3rem;
  height: 1.3rem;
}
/* Con cursor: aparece al pasar sobre la foto (o sobre su tarjeta) y al enfocarla. */
.fa-foto:hover .fa-lupa,
.fa-abrir:focus-visible .fa-lupa,
:global(.reserva-eleccion:hover) .fa-lupa {
  opacity: 1;
}
/* En pantallas táctiles no hay "pasar el cursor": siempre visible. */
@media (hover: none) {
  .fa-lupa {
    opacity: 1;
  }
}
.fa-velo {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgb(0 0 0 / 0.6);
}
.fa-figura {
  position: relative;
  margin: 0;
  padding: 0.75rem;
  border-radius: 16px;
  background: var(--superficie);
  box-shadow: 0 10px 30px rgb(0 0 0 / 0.25);
}
.fa-imagen {
  display: block;
  width: min(22rem, 80vw);
  max-height: 70vh;
  object-fit: cover;
  border-radius: 12px;
}
.fa-nombre {
  margin-top: 0.6rem;
  text-align: center;
  font-weight: 500;
}
.fa-cerrar {
  position: absolute;
  top: 1.1rem;
  right: 1.1rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 999px;
  background: var(--superficie);
  color: var(--texto);
  box-shadow: 0 1px 3px rgb(0 0 0 / 0.2);
}
</style>
