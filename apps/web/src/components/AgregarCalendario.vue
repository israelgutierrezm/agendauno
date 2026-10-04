<script setup lang="ts">
import { ref } from "vue";

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
        class="block px-3 py-2 text-sm hover:bg-[var(--superficie-2)]"
        role="menuitem"
        @click="abierto = false"
        >{{ $t("portal.calendario.google") }}</a
      >
      <button
        type="button"
        class="block w-full px-3 py-2 text-left text-sm hover:bg-[var(--superficie-2)]"
        role="menuitem"
        @click="
          descargarIcs(props.evento);
          abierto = false;
        "
      >
        {{ $t("portal.calendario.ics") }}
      </button>
    </MenuFlotante>
  </div>
</template>
