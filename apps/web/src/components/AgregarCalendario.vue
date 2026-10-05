<script setup lang="ts">
import { ref } from "vue";

import LogoCalendario from "@/components/LogoCalendario.vue";
import MenuFlotante from "@/components/MenuFlotante.vue";
import {
  descargarIcs,
  enlaceGoogle,
  type EventoCalendario,
} from "@/lib/calendario";

/**
 * "Agregar a mi calendario" de una reserva: Google Calendar (en una pestaña nueva) o
 * el archivo .ics para Apple Calendar, Outlook y los demás. Las opciones flotan sobre
 * la página: la tarjeta donde va el botón no las recorta.
 */
const props = defineProps<{ evento: EventoCalendario; primario?: boolean }>();

const abierto = ref(false);
const boton = ref<HTMLElement | null>(null);
</script>

<template>
  <div class="inline-block">
    <button
      ref="boton"
      type="button"
      class="tu-btn text-sm"
      :class="props.primario ? 'tu-btn-primario' : 'tu-btn-fantasma'"
      :aria-expanded="abierto"
      aria-haspopup="menu"
      @click="abierto = !abierto"
    >
      {{ $t("portal.calendario.agregar") }}
    </button>
    <MenuFlotante :abierto="abierto" :ancla="boton" @cerrar="abierto = false">
      <a
        :href="enlaceGoogle(props.evento)"
        target="_blank"
        rel="noopener"
        class="ac-opcion"
        role="menuitem"
        @click="abierto = false"
        ><span class="ac-logos"><LogoCalendario marca="google" /></span
        >{{ $t("portal.calendario.google") }}</a
      >
      <button
        type="button"
        class="ac-opcion"
        role="menuitem"
        @click="
          descargarIcs(props.evento);
          abierto = false;
        "
      >
        <span class="ac-logos"
          ><LogoCalendario marca="apple" /><LogoCalendario marca="outlook"
        /></span>
        {{ $t("portal.calendario.ics") }}
      </button>
    </MenuFlotante>
  </div>
</template>

<style scoped>
/* Cada opción con la miniatura de su app (la del .ics: Apple y Outlook). */
.ac-opcion {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  text-align: left;
}
.ac-opcion:hover {
  background: var(--superficie-2);
}
.ac-logos {
  display: inline-flex;
  flex-shrink: 0;
  gap: 0.2rem;
  min-width: 2.65rem;
}
</style>
