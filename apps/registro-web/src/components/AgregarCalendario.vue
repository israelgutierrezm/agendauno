<script setup lang="ts">
import { ref } from "vue";

import {
  descargarIcs,
  enlaceGoogle,
  type EventoCalendario,
} from "@/lib/calendario";

/**
 * "Agregar a mi calendario" de una reserva: Google Calendar (en una pestaña nueva) o
 * el archivo .ics para Apple Calendar, Outlook y los demás.
 */
const props = defineProps<{ evento: EventoCalendario; primario?: boolean }>();

const abierto = ref(false);
</script>

<template>
  <div class="relative inline-block">
    <button
      type="button"
      class="tu-btn text-sm"
      :class="props.primario ? 'tu-btn-primario' : 'tu-btn-fantasma'"
      :aria-expanded="abierto"
      @click="abierto = !abierto"
    >
      {{ $t("portal.calendario.agregar") }}
    </button>
    <div
      v-if="abierto"
      class="absolute left-0 z-20 mt-1 w-56 overflow-hidden rounded-lg border shadow-lg"
      :style="{ background: 'var(--superficie)', borderColor: 'var(--borde)' }"
      role="menu"
    >
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
    </div>
  </div>
</template>
